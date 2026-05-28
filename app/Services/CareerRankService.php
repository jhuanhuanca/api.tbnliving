<?php

namespace App\Services;

use App\Models\BinaryLegVolumeWeekly;
use App\Models\PeriodClosure;
use App\Models\Rank;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Ascenso de carrera post-fundador: PV de grupo (ligero), frontales activos, PV personal y rangos en línea directa.
 */
class CareerRankService
{
    /** @var array<string, array<int, string>> */
    protected array $openCareerPvCache = [];

    public function __construct(
        protected BinaryService $binaryService
    ) {}

    /**
     * PV “grupo” simplificado para CARRERA/RANGO:
     * - lifetime_qualifying_pv consolidado del usuario
     * - + volumen binario crudo del periodo actual todavía no consolidado
     * - + lo mismo para sus patrocinados directos (excluye preferred_customer)
     *
     * Importante:
     * - monthly_qualifying_pv se usa para snapshots/bonos mensuales (liderazgo).
     * - lifetime_qualifying_pv se usa como base permanente para rangos/carrera.
     * - El volumen abierto del periodo actual se suma para que el avance/rango se vea en tiempo real
     *   y no quede en 0 hasta ejecutar el cierre binario.
     */
    public function groupQualifyingPvLight(User $user): string
    {
        $user->loadMissing('referrals');
        $eligibleReferrals = $user->referrals->filter(function (User $referral) {
            return ! $referral->isPreferredCustomer();
        })->values();

        $ids = array_values(array_unique([
            (int) $user->id,
            ...$eligibleReferrals->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ]));
        $openPvByUser = $this->openCareerPvByUserIds($ids);

        $sum = $this->effectiveCareerPvForUser($user, $openPvByUser);
        foreach ($eligibleReferrals as $r) {
            if ($r->isPreferredCustomer()) {
                continue;
            }
            $sum = bcadd($sum, $this->effectiveCareerPvForUser($r, $openPvByUser), 4);
        }

        return bcadd($sum, '0', 2);
    }

    /**
     * NUEVO PV de carrera (separado):
     * - Solo socios DIRECTOS válidos (sin downline infinito)
     * - Se toma un % (por defecto 60%) del PV acumulado de cada directo
     *
     * Importante:
     * - No reemplaza lifetime_qualifying_pv ni monthly_qualifying_pv.
     * - Se usa solo para carrera/rangos si `mlm.career.use_rank_accumulated_pv = true`.
     */
    public function rankAccumulatedPv(User $user): string
    {
        if ($user->isPreferredCustomer() || $user->canAccessAdminPanel()) {
            return '0.00';
        }

        $minPv = (string) config('mlm.career.direct_active_min_pv', '50');
        $factor = (string) config('mlm.career.rank_accumulated_direct_factor', '0.60');

        /** @var \Illuminate\Support\Collection<int, \App\Models\User> $directs */
        $directs = User::query()
            ->where('sponsor_id', $user->id)
            ->where(function ($w) {
                $w->whereNull('account_type')->orWhere('account_type', 'member');
            })
            ->where('account_status', 'active')
            ->where('monthly_qualifying_pv', '>=', $minPv)
            ->select(['id', 'account_type', 'lifetime_qualifying_pv'])
            ->get();

        if ($directs->isEmpty()) {
            return '0.00';
        }

        $ids = $directs->pluck('id')->map(fn ($id) => (int) $id)->all();
        $openPvByUser = $this->openCareerPvByUserIds($ids);

        $sum = '0';
        foreach ($directs as $d) {
            if ($d->isPreferredCustomer()) {
                continue;
            }
            $pv = $this->effectiveCareerPvForUser($d, $openPvByUser);
            $weighted = bcmul($pv, $factor, 4);
            $sum = bcadd($sum, $weighted, 4);
        }

        return bcadd($sum, '0', 2);
    }

    /**
     * PV acumulado efectivo para carrera (histórico + volumen abierto del periodo actual).
     * Útil para mostrar “PV acumulados” en tarjetas/nodos en tiempo real.
     */
    public function effectiveCareerPv(User $user): string
    {
        return bcadd($this->effectiveCareerPvForUser($user), '0', 2);
    }

    /**
     * Mayor slug de carrera al que el usuario cumple todos los requisitos (orden de mayor a menor).
     * Sin paquete fundador (≥1200 PV del paquete de registro) solo aplica rango base `activo`.
     */
    public function computeHighestEligibleRankSlug(User $user): string
    {
        if ($user->isPreferredCustomer() || $user->canAccessAdminPanel()) {
            return $user->rank?->slug ?? 'activo';
        }

        $minFundadorPv = (string) config('mlm.career.fundador_min_package_pv', '1200');
        $user->loadMissing('registrationPackage');
        $pvPersonalMes = bcadd((string) ($user->monthly_qualifying_pv ?? '0'), '0', 2);
        $pkgPv = bcadd((string) ($user->registrationPackage?->pv_points ?? '0'), '0', 2);
        $pvAccesoCarrera = bcadd($pvPersonalMes, $pkgPv, 2);
        if (bccomp($pvAccesoCarrera, $minFundadorPv, 2) < 0) {
            return 'activo';
        }

        $order = config('mlm.career.rank_eval_order', []);
        $reqs = config('mlm.career.requirements', []);
        if ($order === []) {
            return $user->rank?->slug ?? 'activo';
        }

        $rankSort = $this->rankSortOrderBySlug();

        foreach (array_reverse($order) as $slug) {
            $cfg = $reqs[$slug] ?? null;
            if (! is_array($cfg)) {
                continue;
            }
            if ($this->meetsAll($user, (string) $slug, $cfg, $rankSort)) {
                return (string) $slug;
            }
        }

        return 'activo';
    }

    /**
     * @param  array<string, int|null>  $rankSort  slug => sort_order
     */
    protected function meetsAll(User $user, string $slug, array $cfg, array $rankSort): bool
    {
        $gv = $this->careerPvForRankEval($user);
        $minGv = $this->careerPvRequirementForRankEval($cfg);
        if (bccomp($gv, $minGv, 2) < 0) {
            return false;
        }

        $minPersonal = (string) ($cfg['min_personal_pv'] ?? '0');
        // Activación personal (mensual) — requisito distinto al PV de carrera (histórico).
        $personal = bcadd((string) ($user->monthly_qualifying_pv ?? '0'), '0', 2);
        if (bccomp($personal, $minPersonal, 2) < 0) {
            return false;
        }

        $needDirects = (int) ($cfg['min_direct_actives'] ?? 0);
        if ($needDirects > 0) {
            $minPv = (string) config('mlm.career.direct_active_min_pv', '50');
            $n = User::query()
                ->where('sponsor_id', $user->id)
                ->where(function ($w) {
                    $w->whereNull('account_type')->orWhere('account_type', 'member');
                })
                ->where('account_status', 'active')
                ->where('monthly_qualifying_pv', '>=', $minPv)
                ->count();
            if ($n < $needDirects) {
                return false;
            }
        }

        foreach ($cfg['min_directs_with_rank'] ?? [] as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $needSlug = (string) ($rule['slug'] ?? '');
            $needCount = (int) ($rule['min_count'] ?? 0);
            if ($needSlug === '' || $needCount < 1) {
                continue;
            }
            $minSo = (int) ($rankSort[$needSlug] ?? 999999);
            $count = User::query()
                ->where('sponsor_id', $user->id)
                ->where(function ($w) {
                    $w->whereNull('account_type')->orWhere('account_type', 'member');
                })
                ->whereHas('rank', fn ($q) => $q->where('sort_order', '>=', $minSo))
                ->count();
            if ($count < $needCount) {
                return false;
            }
        }

        return true;
    }

    protected function careerPvForRankEval(User $user): string
    {
        $use = (bool) config('mlm.career.use_rank_accumulated_pv', true);
        if (! $use) {
            return $this->groupQualifyingPvLight($user);
        }

        return $this->rankAccumulatedPv($user);
    }

    protected function careerPvRequirementForRankEval(array $cfg): string
    {
        // Permite extender sin romper config actual:
        // si existe `min_rank_accumulated_pv`, se prioriza; si no, usa `min_group_pv_light`.
        if (isset($cfg['min_rank_accumulated_pv'])) {
            return (string) $cfg['min_rank_accumulated_pv'];
        }

        return (string) ($cfg['min_group_pv_light'] ?? '0');
    }

    /**
     * @return array<string, int>
     */
    protected function rankSortOrderBySlug(): array
    {
        return Cache::remember('mlm:rank_sort_by_slug', 3600, function () {
            return Rank::query()->pluck('sort_order', 'slug')->all();
        });
    }

    public static function forgetRankSortCache(): void
    {
        Cache::forget('mlm:rank_sort_by_slug');
    }

    /**
     * Rango a mostrar / conservar título: el mayor entre el persistido en BD y el calculado por reglas del mes.
     * Así no “pierdes” el nombre de rango al reiniciar PV mensual si el rango ya no se degrada en BD.
     */
    public function displayRankSlug(User $user): string
    {
        $user->loadMissing('rank');
        $rankSort = $this->rankSortOrderBySlug();
        $stored = (string) ($user->rank?->slug ?? '');
        if ($stored === '' || $stored === 'sin_rango') {
            $stored = 'activo';
        }
        $computed = $this->computeHighestEligibleRankSlug($user);

        return $this->maxRankSlugBySort($stored, $computed, $rankSort);
    }

    /**
     * @param  array<string, int|null>  $rankSort
     */
    protected function maxRankSlugBySort(string $slugA, string $slugB, array $rankSort): string
    {
        $soA = (int) ($rankSort[$slugA] ?? -1);
        $soB = (int) ($rankSort[$slugB] ?? -1);

        return $soA >= $soB ? $slugA : $slugB;
    }

    protected function effectiveCareerPvForUser(User $user, ?array $openPvByUser = null): string
    {
        $openPvByUser = $openPvByUser ?? $this->openCareerPvByUserIds([(int) $user->id]);
        $base = bcadd((string) ($user->lifetime_qualifying_pv ?? '0'), '0', 4);
        $open = bcadd((string) ($openPvByUser[(int) $user->id] ?? '0'), '0', 4);

        return bcadd($base, $open, 4);
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, string>
     */
    protected function openCareerPvByUserIds(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds, fn ($id) => (int) $id > 0)));
        if ($userIds === []) {
            return [];
        }

        sort($userIds);
        $cacheKey = implode(',', $userIds);
        if (isset($this->openCareerPvCache[$cacheKey])) {
            return $this->openCareerPvCache[$cacheKey];
        }

        $periodKey = $this->binaryService->volumePeriodKey(Carbon::now());
        $closure = PeriodClosure::query()
            ->where('period_type', $this->binaryService->binaryPeriodTypeForClosure())
            ->where('period_key', $periodKey)
            ->where('scope', 'binary')
            ->first();

        if (($closure?->meta['career_pv_applied'] ?? false) === true || $closure?->status === 'finished') {
            return $this->openCareerPvCache[$cacheKey] = [];
        }

        $rows = BinaryLegVolumeWeekly::query()
            ->selectRaw('parent_user_id, SUM(volume_pv) as group_pv')
            ->where('week_key', $periodKey)
            ->whereIn('parent_user_id', $userIds)
            ->groupBy('parent_user_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->parent_user_id] = bcadd((string) ($row->group_pv ?? '0'), '0', 4);
        }

        return $this->openCareerPvCache[$cacheKey] = $out;
    }

    /**
     * Primer rango de carrera (en orden ascendente) que el usuario aún no cumple por completo.
     *
     * @return array{
     *   next_slug: ?string,
     *   next_min_group_pv: ?string,
     *   current_group_pv_light: string,
     *   percent_pv: float|null,
     *   missing_messages: list<string>,
     *   at_career_cap: bool
     * }
     */
    public function describeNextCareerStep(User $user): array
    {
        $order = config('mlm.career.rank_eval_order', []);
        $reqs = config('mlm.career.requirements', []);
        $rankSort = $this->rankSortOrderBySlug();

        $user->loadMissing('registrationPackage', 'referrals.rank', 'rank');
        $gv = $this->careerPvForRankEval($user);
        $minFundadorPv = (string) config('mlm.career.fundador_min_package_pv', '1200');
        $pvPersonalMes = bcadd((string) ($user->monthly_qualifying_pv ?? '0'), '0', 2);
        $pkgPv = bcadd((string) ($user->registrationPackage?->pv_points ?? '0'), '0', 2);
        $pvAccesoCarrera = bcadd($pvPersonalMes, $pkgPv, 2);

        if (bccomp($pvAccesoCarrera, $minFundadorPv, 2) < 0) {
            $firstSlug = null;
            $firstCfg = null;
            foreach ($order as $slug) {
                $cfg = $reqs[$slug] ?? null;
                if (is_array($cfg)) {
                    $firstSlug = $slug;
                    $firstCfg = $cfg;
                    break;
                }
            }

            $firstMin = $this->careerPvRequirementForRankEval($firstCfg);
            $percent = null;
            if (bccomp($firstMin, '0', 2) === 1) {
                $percent = (float) bcmul(bcdiv($gv, $firstMin, 6), '100', 4);
                $percent = min(100.0, max(0.0, $percent));
            }

            return [
                'next_slug' => $firstSlug,
                'next_min_group_pv' => $firstMin !== '0' ? $firstMin : null,
                'current_group_pv_light' => $gv,
                'percent_pv' => $percent,
                'missing_messages' => [
                    'Completa el paquete Fundador ('.$minFundadorPv.' PV) para acceder a rangos de carrera.',
                ],
                'at_career_cap' => false,
            ];
        }

        // Siguiente meta de carrera respecto al rango mostrado (máx. entre persistido y elegible hoy),
        // sin “volver a Plata” solo porque el PV personal mensual se reinició: el % sigue el PV de grupo.
        $displaySlug = $this->displayRankSlug($user);
        $displaySo = (int) ($rankSort[$displaySlug] ?? -1);

        $nextSlug = null;
        $nextCfg = null;
        foreach ($order as $slug) {
            $so = (int) ($rankSort[$slug] ?? 0);
            if ($so <= $displaySo) {
                continue;
            }
            $cfg = $reqs[$slug] ?? null;
            if (! is_array($cfg)) {
                continue;
            }
            $nextSlug = $slug;
            $nextCfg = $cfg;
            break;
        }

        if ($nextSlug === null || $nextCfg === null) {
            return [
                'next_slug' => null,
                'next_min_group_pv' => null,
                'current_group_pv_light' => $gv,
                'percent_pv' => 100.0,
                'missing_messages' => [],
                'at_career_cap' => true,
            ];
        }

        $nextMin = $this->careerPvRequirementForRankEval($nextCfg);
        $prevMin = '0';
        if (isset($reqs[$displaySlug])) {
            $prevMin = $this->careerPvRequirementForRankEval((array) $reqs[$displaySlug]);
        } elseif ($displaySlug === 'activo' || $displaySlug === 'sin_rango') {
            $prevMin = '0';
        } else {
            foreach ($order as $s) {
                if ($s === $displaySlug) {
                    break;
                }
                $c = $reqs[$s] ?? [];
                if (is_array($c)) {
                    $prevMin = $this->careerPvRequirementForRankEval($c);
                }
            }
        }

        $percent = null;
        $span = bcsub($nextMin, $prevMin, 2);
        if (bccomp($span, '0', 2) === 1) {
            $done = bcsub($gv, $prevMin, 2);
            $percent = (float) bcmul(bcdiv($done, $span, 6), '100', 4);
            $percent = min(100.0, max(0.0, $percent));
        }

        $missing = $this->missingRequirementMessages($user, $nextSlug, $nextCfg, $rankSort);

        return [
            'next_slug' => $nextSlug,
            'next_min_group_pv' => $nextMin,
            'current_group_pv_light' => $gv,
            'percent_pv' => $percent,
            'missing_messages' => $missing,
            'at_career_cap' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, int|null>  $rankSort
     * @return list<string>
     */
    protected function missingRequirementMessages(User $user, string $slug, array $cfg, array $rankSort): array
    {
        $out = [];
        $gv = $this->careerPvForRankEval($user);
        $minGv = $this->careerPvRequirementForRankEval($cfg);
        if (bccomp($gv, $minGv, 2) < 0) {
            $out[] = 'PV de grupo (estimado): '.$gv.' / '.$minGv.' hacia '.$slug;
        }

        $minPersonal = (string) ($cfg['min_personal_pv'] ?? '0');
        $personal = (string) ($user->monthly_qualifying_pv ?? '0');
        if (bccomp($personal, $minPersonal, 2) < 0) {
            $out[] = 'Activación personal: '.$personal.' / '.$minPersonal.' PV este mes';
        }

        $needDirects = (int) ($cfg['min_direct_actives'] ?? 0);
        if ($needDirects > 0) {
            $minPv = (string) config('mlm.career.direct_active_min_pv', '50');
            $n = User::query()
                ->where('sponsor_id', $user->id)
                ->where(function ($w) {
                    $w->whereNull('account_type')->orWhere('account_type', 'member');
                })
                ->where('account_status', 'active')
                ->where('monthly_qualifying_pv', '>=', $minPv)
                ->count();
            if ($n < $needDirects) {
                $out[] = 'Frontales directos activos: '.$n.' / '.$needDirects.' (≥ '.$minPv.' PV c/u)';
            }
        }

        foreach ($cfg['min_directs_with_rank'] ?? [] as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $needSlug = (string) ($rule['slug'] ?? '');
            $needCount = (int) ($rule['min_count'] ?? 0);
            if ($needSlug === '' || $needCount < 1) {
                continue;
            }
            $minSo = (int) ($rankSort[$needSlug] ?? 999999);
            $count = User::query()
                ->where('sponsor_id', $user->id)
                ->where(function ($w) {
                    $w->whereNull('account_type')->orWhere('account_type', 'member');
                })
                ->whereHas('rank', fn ($q) => $q->where('sort_order', '>=', $minSo))
                ->count();
            if ($count < $needCount) {
                $out[] = 'Directos con rango ≥ '.$needSlug.': '.$count.' / '.$needCount;
            }
        }

        return $out;
    }
}
