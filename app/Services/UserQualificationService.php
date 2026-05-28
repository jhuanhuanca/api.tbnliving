<?php

namespace App\Services;

use App\Events\UserActivated;
use App\Models\BinaryLegVolumeWeekly;
use App\Models\BinaryPlacement;
use App\Models\Order;
use App\Models\User;
use App\Models\UserMonthlyRankSnapshot;
use Carbon\Carbon;

class UserQualificationService
{
    public function __construct(
        protected RankService $rankService,
        protected BinaryService $binaryService,
        protected LeadershipStreakService $leadershipStreakService,
        protected LeadershipBonusAccrualService $leadershipBonusAccrualService,
        protected MonthlyActivationService $monthlyActivationService
    ) {}

    /**
     * PV mensual de activación: paquetes + productos (si una sola compra ≥ umbral del rango).
     */
    public function computeMonthlyQualifyingPv(User $user, ?string $monthKey = null): string
    {
        if ($user->isPreferredCustomer() || $user->canAccessAdminPanel()) {
            return '0';
        }

        $user->loadMissing('rank');
        $month = $monthKey ?? Carbon::now()->format('Y-m');
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = Carbon::createFromFormat('Y-m', $month)->endOfMonth()->endOfDay();
        $threshold = $this->monthlyActivationService->requiredPvForUser($user);

        $fromOrders = Order::sumMonthlyActivationPvForUserBetween((int) $user->id, $start, $end, $threshold);
        $fromRegistration = $this->registrationPackagePvForMonth($user, $start, $end);

        return bcadd($fromOrders, $fromRegistration, 2);
    }

    /**
     * Reconcilia calificación si el PV almacenado no coincide con el cálculo real.
     */
    public function syncMonthlyQualificationIfStale(User $user): User
    {
        $month = Carbon::now()->format('Y-m');
        $computed = $this->computeMonthlyQualifyingPv($user, $month);
        $stored = bcadd((string) ($user->monthly_qualifying_pv ?? '0'), '0', 2);
        $shouldQualify = $this->monthlyActivationService->isQualified($user, $computed);
        $lastMonth = (string) ($user->last_qualification_month ?? '');

        if ($lastMonth !== $month
            || bccomp($computed, $stored, 2) !== 0
            || (bool) $user->is_mlm_qualified !== $shouldQualify) {
            $this->actualizarCalificacionMensual($user);

            return $user->fresh(['rank', 'registrationPackage', 'sponsor', 'country', 'referrals.rank']) ?? $user;
        }

        return $user;
    }

    public function actualizarCalificacionMensual(User $user): void
    {
        $month = Carbon::now()->format('Y-m');

        $user->loadMissing('rank');
        $pv = $this->computeMonthlyQualifyingPv($user, $month);
        $qualified = $this->monthlyActivationService->isQualified($user, $pv);

        $was = (bool) $user->is_mlm_qualified;

        // Regla de cuenta:
        // - No marcar "active" solo por calificación mensual.
        // - Un socio pasa a "active" al pagar su paquete de activación (activation_paid_at) y verificar correo.
        $newStatus = $user->account_status;
        if ($user->activation_paid_at !== null && $user->email_verified_at !== null) {
            $newStatus = 'active';
        } elseif ($newStatus === null || $newStatus === '') {
            $newStatus = 'pending';
        }

        $user->forceFill([
            'last_qualification_month' => $month,
            'monthly_qualifying_pv' => $pv,
            'is_mlm_qualified' => $qualified,
            'account_status' => $newStatus,
        ])->save();

        $this->rankService->sincronizarRangoPorCalificacion($user->fresh());

        $userFresh = $user->fresh(['rank']);
        if ($userFresh) {
            $this->persistirSnapshotMensual($userFresh, $month, $pv);
            $this->leadershipBonusAccrualService->processUserMonth($userFresh, $month);
            if ($userFresh->sponsor_id) {
                $sponsor = User::query()->find((int) $userFresh->sponsor_id);
                if ($sponsor) {
                    $this->leadershipBonusAccrualService->processUserMonth($sponsor, $month);
                }
            }
        }

        if (! $was && $qualified) {
            UserActivated::dispatch($user->fresh(), $month);
        }
    }

    /**
     * PV del paquete de inscripción en el mes de activación, si aún no está en un pedido del mes.
     */
    protected function registrationPackagePvForMonth(User $user, Carbon $start, Carbon $end): string
    {
        $user->loadMissing('registrationPackage');
        $pkg = $user->registrationPackage;
        if (! $pkg || $user->activation_paid_at === null) {
            return '0';
        }

        $paidAt = Carbon::parse($user->activation_paid_at);
        if ($paidAt->lt($start) || $paidAt->gt($end)) {
            return '0';
        }

        $pkgId = (int) ($user->registration_package_id ?? 0);
        if ($pkgId > 0 && $this->orderMonthIncludesPackage((int) $user->id, $pkgId, $start, $end)) {
            return '0';
        }

        return bcadd((string) ($pkg->pv_points ?? '0'), '0', 2);
    }

    protected function orderMonthIncludesPackage(int $userId, int $packageId, Carbon $start, Carbon $end): bool
    {
        return Order::query()
            ->where('user_id', $userId)
            ->where('estado', 'completado')
            ->whereBetween('completed_at', [$start, $end])
            ->whereHas('items', fn ($q) => $q->where('package_id', $packageId))
            ->exists();
    }

    protected function persistirSnapshotMensual(User $user, string $month, string $qualifyingPv): void
    {
        $periodKey = $this->binaryService->volumePeriodKey(now());
        $leftPv = (string) BinaryLegVolumeWeekly::query()
            ->where('parent_user_id', $user->id)
            ->where('week_key', $periodKey)
            ->where('leg_position', BinaryPlacement::LEG_LEFT)
            ->value('volume_pv') ?? '0';
        $rightPv = (string) BinaryLegVolumeWeekly::query()
            ->where('parent_user_id', $user->id)
            ->where('week_key', $periodKey)
            ->where('leg_position', BinaryPlacement::LEG_RIGHT)
            ->value('volume_pv') ?? '0';

        $streak = $this->leadershipStreakService->mesesConsecutivosMismoRango($user, $month);

        UserMonthlyRankSnapshot::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'month_key' => $month,
            ],
            [
                'rank_id' => $user->rank_id,
                'rank_slug' => $user->rank?->slug,
                'qualifying_pv' => $qualifyingPv,
                'leadership_streak_months' => $streak,
                'binary_left_pv' => (float) ($leftPv ?? 0),
                'binary_right_pv' => (float) ($rightPv ?? 0),
                'meta' => [
                    'binary_volume_period_key' => $periodKey,
                    'binary_volume_period' => $this->binaryService->isMonthlyBinaryVolume() ? 'monthly' : 'weekly',
                ],
            ]
        );
    }
}
