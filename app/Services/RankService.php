<?php

namespace App\Services;

use App\Models\Rank;
use App\Models\User;
use App\Events\Internal\RankUpdated;

/**
 * Motor de rango: carrera post-fundador (CareerRankService) + rangos en BD.
 */
class RankService
{
    public function __construct(
        protected CareerRankService $careerRankService
    ) {}

    /**
     * Asigna el mayor rango de carrera cuyos requisitos cumple el socio (post-paquete fundador).
     */
    public function sincronizarRangoPorCalificacion(User $user): void
    {
        if ($user->canAccessAdminPanel() || $user->isPreferredCustomer()) {
            return;
        }

        $user->loadMissing('registrationPackage', 'referrals', 'rank');
        $slug = $this->careerRankService->computeHighestEligibleRankSlug($user);

        $rank = Rank::query()->where('slug', $slug)->first();
        if (! $rank) {
            return;
        }

        if ((int) $user->rank_id === (int) $rank->id) {
            return;
        }

        $allowDowngrade = (bool) config('mlm.rank.allow_downgrade_on_monthly_eval', false);
        if (! $allowDowngrade) {
            $current = $user->rank;
            $newSo = (int) ($rank->sort_order ?? 0);
            $curSo = (int) ($current?->sort_order ?? 0);
            if ($current && $newSo <= $curSo) {
                return;
            }
        }

        $old = $user->rank_id !== null ? (int) $user->rank_id : null;
        $new = (int) $rank->id;
        $user->forceFill(['rank_id' => $new])->save();
        RankUpdated::dispatch($user->fresh(), $old, $new);
    }

    /**
     * Re-evalúa todos los socios (chunk) — programar vía consola / schedule.
     */
    public function reevaluarTodosLosRangos(): int
    {
        $updated = 0;
        User::query()
            ->where(function ($q) {
                $q->whereNull('account_type')->orWhere('account_type', 'member');
            })
            ->chunkById(500, function ($users) use (&$updated) {
            foreach ($users as $user) {
                $before = $user->rank_id;
                $this->sincronizarRangoPorCalificacion($user);
                if ((int) $user->fresh()->rank_id !== (int) $before) {
                    $updated++;
                }
            }
        });

        return $updated;
    }

    public function slugEfectivoParaResidual(User $sponsor): string
    {
        $sponsor->loadMissing('referrals');
        $use = (bool) config('mlm.residual.use_rank_accumulated_pv', true);
        $pv = $use
            ? $this->careerRankService->rankAccumulatedPv($sponsor)
            : $this->careerRankService->groupQualifyingPvLight($sponsor);
        $thresholds = config('mlm.residual.rank_thresholds_pv', []);
        if ($thresholds === []) {
            return 'default';
        }

        uasort($thresholds, fn ($a, $b) => $b <=> $a);

        foreach ($thresholds as $slug => $min) {
            if (bccomp($pv, (string) $min, 2) >= 0) {
                return (string) $slug;
            }
        }

        return 'default';
    }
}
