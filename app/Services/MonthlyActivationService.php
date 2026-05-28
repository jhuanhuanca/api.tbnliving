<?php

namespace App\Services;

use App\Models\Rank;
use App\Models\User;

/**
 * Activación mensual MLM: umbral por rango y calificación sobre PV de paquetes personales.
 */
class MonthlyActivationService
{
    /**
     * PV mínimo del mes según rango de carrera alcanzado.
     * - Por debajo de diamante_ejecutivo: direct_active_min_pv (50).
     * - diamante_ejecutivo y superiores: min_pv_diamond_plus (100).
     */
    public function requiredPvForUser(User $user): string
    {
        $minDefault = bcadd((string) config('mlm.career.direct_active_min_pv', '50'), '0', 2);
        $minDiamondPlus = bcadd((string) config('mlm.career.monthly_activation.min_pv_diamond_plus', '100'), '0', 2);
        $fromSlug = (string) config('mlm.career.monthly_activation.diamond_plus_from_rank_slug', 'diamante_ejecutivo');

        if ($this->userHasRankAtLeast($user, $fromSlug)) {
            return $minDiamondPlus;
        }

        return $minDefault;
    }

    public function isQualified(User $user, ?string $monthlyPv = null): bool
    {
        if ($user->canAccessAdminPanel() || $user->isPreferredCustomer()) {
            return false;
        }

        $pv = bcadd((string) ($monthlyPv ?? $user->monthly_qualifying_pv ?? '0'), '0', 2);
        $threshold = $this->requiredPvForUser($user);

        return bccomp($pv, $threshold, 2) >= 0;
    }

    protected function userHasRankAtLeast(User $user, string $minSlug): bool
    {
        $user->loadMissing('rank');
        $userRank = $user->rank;
        if (! $userRank) {
            return false;
        }

        $minSort = Rank::query()->where('slug', $minSlug)->value('sort_order');
        if ($minSort === null) {
            return false;
        }

        return (int) $userRank->sort_order >= (int) $minSort;
    }
}
