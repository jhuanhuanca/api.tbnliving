<?php

namespace App\Services;

use App\Models\CommissionEvent;
use App\Models\LeadershipBonusPayout;
use App\Models\Rank;
use App\Models\User;
use App\Models\UserMonthlyRankSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bono de liderazgo (según fórmula pedida) integrado a BD:
 * - Usa snapshots mensuales (`user_monthly_rank_snapshots`) para PV del mes y racha de rango.
 * - Calcula PV equipo mensual (light): PV propio + suma PV de directos (excluye preferred_customer).
 * - Si teamPV >= requiredPV y streak >= N, entonces:
 *      bonusPV = requiredPV * leadershipRate
 *      bonusBOB = bonusPV * bobPerPv
 * - Acredita como CommissionEvent type=leadership, period_type=monthly, period_key=monthKey.
 */
class LeadershipBonusAccrualService
{
    public function __construct(
        protected CommissionService $commissionService,
        protected CareerRankService $careerRankService,
        protected LeadershipRequalificationService $requalificationService,
        protected LeadershipBonusPayoutLedger $payoutLedger,
    ) {}

    /**
     * Regla especial "primera vez por rango":
     * - Para el primer pago de liderazgo de un rango (rank_slug), se exige además tener 2 directos activos.
     * - En meses posteriores para el mismo rango, se mantiene la regla estándar (teamPV mensual >= requiredPV)
     *   sin esa condición extra.
     */
    protected function isFirstLeadershipForRank(User $user, string $rankSlug): bool
    {
        $slug = trim($rankSlug);
        if ($slug === '') {
            return false;
        }

        return ! CommissionEvent::query()
            ->where('beneficiary_user_id', $user->id)
            ->where('type', 'leadership')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.rank_slug')) = ?", [$slug])
            ->exists();
    }

    protected function hasTwoDirectActives(User $user): bool
    {
        $minPv = (string) config('mlm.career.direct_active_min_pv', '50');
        $count = User::query()
            ->where('sponsor_id', $user->id)
            ->where(function ($w) {
                $w->whereNull('account_type')->orWhere('account_type', 'member');
            })
            ->where('account_status', 'active')
            ->where('monthly_qualifying_pv', '>=', $minPv)
            ->count();

        return $count >= 2;
    }

    public function processUserMonth(User $user, string $monthKey): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $monthKey)) {
            $monthKey = Carbon::now()->format('Y-m');
        }

        if ($user->isPreferredCustomer() || $user->canAccessAdminPanel() || $user->account_status !== 'active') {
            return;
        }

        $snap = UserMonthlyRankSnapshot::query()
            ->where('user_id', $user->id)
            ->where('month_key', $monthKey)
            ->first();
        if (! $snap) {
            return;
        }

        $rankId = (int) ($snap->rank_id ?? 0);
        if ($rankId <= 0) {
            return;
        }

        /** @var Rank|null $rank */
        $rank = Rank::query()->find($rankId);
        if (! $rank) {
            return;
        }

        $rate = $this->clampBetweenZeroOne((string) ($rank->leadership_rate ?? '0'), 6);
        if (bccomp($rate, '0', 6) !== 1) {
            return;
        }

        $requiredPv = $this->requiredPvForRankSlug((string) ($snap->rank_slug ?? $rank->slug ?? ''));
        $requiredPv = $this->clampNonNegativeDecimal($requiredPv, 4);
        if (bccomp($requiredPv, '0', 4) !== 1) {
            return;
        }

        $qualifyingPv = $this->qualifyingPvForLeadership($user, $monthKey);
        if (bccomp($qualifyingPv, $requiredPv, 4) === -1) {
            return;
        }

        $rankSlug = (string) ($snap->rank_slug ?? $rank->slug ?? '');
        // Primera vez por rango: exigir 2 directos activos.
        if ($this->isFirstLeadershipForRank($user, $rankSlug) && ! $this->hasTwoDirectActives($user)) {
            return;
        }

        // Control enterprise: máximo 3 pagos por rango (1 inicial + 2 requal) y 1 pago por mes.
        $state = $this->requalificationService->statusFor((int) $user->id, (int) $rankId, $monthKey);
        if (! ($state['leadership_bonus_eligibility'] ?? false)) {
            return;
        }

        $bobPerPv = (string) config('mlm.pv_value.bob_per_pv', '7');
        $amount = $this->leadershipAmountFromRequiredPv($requiredPv, $rate, $bobPerPv);
        if (bccomp($amount, '0', 2) !== 1) {
            return;
        }

        $payout = $this->reserveLeadershipPayout(
            userId: (int) $user->id,
            rankId: (int) $rankId,
            monthKey: $monthKey,
            requiredPv: $requiredPv,
            achievedPv: $qualifyingPv,
            rankAccumulatedPv: $this->careerRankService->rankAccumulatedPv($user),
            percentage: $rate,
            amount: $amount,
            state: $state,
            metadata: [
                'rank_slug' => $rankSlug,
                'source' => 'processUserMonth',
            ],
        );
        if (! $payout) {
            return;
        }

        try {
            // Idempotente por mes: leadership:u:{id}:{monthKey}
            $this->commissionService->calcularLiderazgo($user->loadMissing('rank'), $monthKey, $requiredPv, $rate, $rankSlug);
            $this->requalificationService->markPaid((int) $user->id, (int) $rankId, $monthKey);
            $this->payoutLedger->markProcessed($payout);
        } catch (\Throwable $e) {
            $this->payoutLedger->markFailed($payout, $e->getMessage());
            throw $e;
        }
    }

    public function processMonth(string $monthKey): void
    {
        // Mes anterior por defecto si viene vacío/incorrecto
        if (! preg_match('/^\d{4}-\d{2}$/', $monthKey)) {
            $monthKey = Carbon::now()->subMonth()->format('Y-m');
        }

        $bobPerPv = (string) config('mlm.pv_value.bob_per_pv', '7');

        // Cache simple en memoria por ejecución (evita queries repetidas).
        $rankById = [];

        User::query()
            ->with('rank')
            ->where(function ($w) {
                // Solo socios (member o legacy null)
                $w->whereNull('account_type')->orWhere('account_type', 'member');
            })
            ->where('account_status', 'active')
            ->whereHas('rank', fn ($q) => $q->where('leadership_rate', '>', 0))
            ->orderBy('id')
            ->chunkById(200, function ($users) use ($monthKey, $bobPerPv, &$rankById) {
                foreach ($users as $u) {
                    $snap = UserMonthlyRankSnapshot::query()
                        ->where('user_id', $u->id)
                        ->where('month_key', $monthKey)
                        ->first();
                    if (! $snap) {
                        // Sin snapshot mensual no hay base confiable para liderazgo.
                        continue;
                    }

                    // Regla corregida:
                    // - El bono se evalúa MES A MES.
                    // - Se paga solo si en ese mes alcanzó el PV requerido del rango (según snapshot del mes).
                    // - No requiere "racha de 3 meses"; cada mes es independiente.

                    $rankId = (int) ($snap->rank_id ?? 0);
                    if ($rankId <= 0) {
                        continue;
                    }

                    if (! isset($rankById[$rankId])) {
                        $rankById[$rankId] = Rank::query()->find($rankId);
                    }
                    /** @var Rank|null $rank */
                    $rank = $rankById[$rankId];
                    if (! $rank) {
                        continue;
                    }

                    $rate = $this->clampBetweenZeroOne((string) ($rank->leadership_rate ?? '0'), 6);
                    if (bccomp($rate, '0', 6) !== 1) {
                        continue;
                    }

                    // El PV requerido se evalúa según el rango del SNAPSHOT del mes (no el rango actual).
                    // Esto hace el cálculo consistente históricamente aunque el usuario cambie de rango después
                    // o se ajusten requisitos en config.
                    $requiredPv = $this->requiredPvForRankSlug((string) ($snap->rank_slug ?? $rank->slug ?? ''));
                    $requiredPv = $this->clampNonNegativeDecimal($requiredPv, 4);
                    if (bccomp($requiredPv, '0', 4) !== 1) {
                        continue;
                    }

                    $qualifyingPv = $this->qualifyingPvForLeadership($u, $monthKey);
                    if (bccomp($qualifyingPv, $requiredPv, 4) === -1) {
                        continue;
                    }

                    $rankSlug = (string) ($snap->rank_slug ?? $rank->slug ?? '');
                    // Primera vez por rango: exigir 2 directos activos.
                    if ($this->isFirstLeadershipForRank($u, $rankSlug) && ! $this->hasTwoDirectActives($u)) {
                        continue;
                    }

                    $state = $this->requalificationService->statusFor((int) $u->id, (int) $rankId, $monthKey);
                    if (! ($state['leadership_bonus_eligibility'] ?? false)) {
                        continue;
                    }

                    $bonusPv = bcmul($requiredPv, $rate, 6);
                    $amount = $this->roundMoney(bcmul($bonusPv, (string) $bobPerPv, 4));
                    if (bccomp($amount, '0', 2) !== 1) {
                        continue;
                    }

                    $payout = $this->reserveLeadershipPayout(
                        userId: (int) $u->id,
                        rankId: (int) $rankId,
                        monthKey: $monthKey,
                        requiredPv: $requiredPv,
                        achievedPv: $qualifyingPv,
                        rankAccumulatedPv: $this->careerRankService->rankAccumulatedPv($u),
                        percentage: $rate,
                        amount: $amount,
                        state: $state,
                        metadata: [
                            'rank_slug' => $rankSlug,
                            'source' => 'processMonth',
                        ],
                    );
                    if (! $payout) {
                        continue;
                    }

                    // Idempotencia: CommissionService ya usa "leadership:u:{id}:{month}".
                    // Usamos la tasa del rango del snapshot (históricamente consistente).
                    try {
                        $this->commissionService->calcularLiderazgo($u, $monthKey, $requiredPv, $rate, $rankSlug);
                        $this->requalificationService->markPaid((int) $u->id, (int) $rankId, $monthKey);
                        $this->payoutLedger->markProcessed($payout);
                    } catch (\Throwable $e) {
                        $this->payoutLedger->markFailed($payout, $e->getMessage());
                        throw $e;
                    }
                }
            });
    }

    protected function leadershipAmountFromRequiredPv(string $requiredPv, string $rate, string $bobPerPv): string
    {
        $bonusPv = bcmul($requiredPv, $rate, 6);
        return $this->roundMoney(bcmul($bonusPv, (string) $bobPerPv, 4));
    }

    /**
     * Crea el ledger row append-only con UNIQUE anti-duplicado.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $metadata
     */
    protected function reserveLeadershipPayout(
        int $userId,
        int $rankId,
        string $monthKey,
        string $requiredPv,
        string $achievedPv,
        string $rankAccumulatedPv,
        string $percentage,
        string $amount,
        array $state,
        array $metadata
    ): ?LeadershipBonusPayout {
        $paidCount = (int) ($state['leadership_bonus_paid_count'] ?? 0);
        $requalCount = (int) ($state['leadership_requalification_count'] ?? 0);
        $isInitial = $paidCount === 0;

        $qualificationType = $isInitial ? 'initial' : 'requalification';
        // Requalification number: 0 (inicial), 1..2 (requals)
        $requalificationNumber = $isInitial ? 0 : min(2, $requalCount + 1);

        return $this->payoutLedger->reserve([
            'user_id' => $userId,
            'rank_id' => $rankId,
            'month_key' => $monthKey,
            'bonus_type' => 'leadership',
            'qualification_type' => $qualificationType,
            'amount' => $amount,
            'percentage' => $percentage,
            'required_pv' => $requiredPv,
            'achieved_pv' => $achievedPv,
            'rank_accumulated_pv' => $rankAccumulatedPv,
            'requalification_number' => $requalificationNumber,
            'is_initial_payment' => $isInitial,
            'status' => 'pending',
            'metadata' => array_merge([
                'dedupe_key' => "u:{$userId}:r:{$rankId}:m:{$monthKey}:leadership",
            ], $metadata),
        ]);
    }

    protected function qualifyingPvForLeadership(User $user, string $monthKey): string
    {
        $use = (bool) config('mlm.leadership.use_rank_accumulated_pv', true);
        if (! $use) {
            return $this->teamPvLightFromSnapshots((int) $user->id, $monthKey);
        }

        // Base calificadora nueva: PV acumulado de directos (60%).
        return bcadd($this->careerRankService->rankAccumulatedPv($user), '0', 4);
    }

    protected function teamPvLightFromSnapshots(int $userId, string $monthKey): string
    {
        $own = (string) UserMonthlyRankSnapshot::query()
            ->where('user_id', $userId)
            ->where('month_key', $monthKey)
            ->value('qualifying_pv') ?? '0';
        $own = $this->clampNonNegativeDecimal($own, 4);

        // PV de directos (account_type member/null)
        $directIds = User::query()
            ->where('sponsor_id', $userId)
            ->where(function ($w) {
                $w->whereNull('account_type')->orWhere('account_type', 'member');
            })
            ->pluck('id')
            ->all();

        if ($directIds === []) {
            return $own;
        }

        $sumDirect = (string) UserMonthlyRankSnapshot::query()
            ->where('month_key', $monthKey)
            ->whereIn('user_id', $directIds)
            ->sum('qualifying_pv');

        $sumDirect = $this->clampNonNegativeDecimal($sumDirect, 4);
        return bcadd($own, $sumDirect, 4);
    }

    protected function requiredPvForRankSlug(string $slug): string
    {
        $map = config('mlm.leadership.required_pv_by_rank_slug', []);
        if ($slug !== '' && isset($map[$slug])) {
            return (string) $map[$slug];
        }

        $careerAcc = config("mlm.career.requirements.{$slug}.min_rank_accumulated_pv");
        if ($careerAcc !== null) {
            return (string) $careerAcc;
        }

        $career = config("mlm.career.requirements.{$slug}.min_group_pv_light");
        if ($career !== null) {
            return (string) $career;
        }

        return '0';
    }

    protected function roundMoney(string $amount): string
    {
        return bcadd($amount, '0', 2);
    }

    protected function clampNonNegativeDecimal(string $v, int $scale): string
    {
        $n = trim($v);
        if ($n === '' || ! is_numeric($n)) {
            return bcadd('0', '0', $scale);
        }
        if (str_starts_with($n, '-')) {
            return bcadd('0', '0', $scale);
        }

        return bcadd($n, '0', $scale);
    }

    protected function clampBetweenZeroOne(string $v, int $scale): string
    {
        $n = $this->clampNonNegativeDecimal($v, $scale);
        if (bccomp($n, '1', $scale) === 1) {
            return bcadd('1', '0', $scale);
        }

        return $n;
    }
}

