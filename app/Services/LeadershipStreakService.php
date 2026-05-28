<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserMonthlyRankSnapshot;
use Carbon\Carbon;

/**
 * Racha de meses consecutivos al mismo rango (persistencia vía user_monthly_rank_snapshots).
 */
class LeadershipStreakService
{
    public function isValidMonthKey(string $monthKey): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}$/', $monthKey);
    }

    public function diaDentroDelCiclo(?Carbon $date = null): bool
    {
        $d = $date ?? Carbon::now();
        $end = (int) config('mlm.qualification_cycle.end_day', 27);

        return (int) $d->day <= $end;
    }

    /**
     * Cuenta meses consecutivos hacia atrás desde monthKey con el mismo rank_id que el usuario tiene hoy.
     * El mes actual usa el rango en vivo si aún no hay snapshot histórico para meses anteriores.
     */
    public function mesesConsecutivosMismoRango(User $user, string $monthKey): int
    {
        if (! $this->isValidMonthKey($monthKey)) {
            $monthKey = Carbon::now()->format('Y-m');
        }

        $target = (int) ($user->rank_id ?? 0);
        if ($target === 0) {
            return 0;
        }

        $count = 0;
        $cursor = Carbon::createFromFormat('Y-m', $monthKey)->startOfMonth();

        for ($i = 0; $i < 36; $i++) {
            $mk = $cursor->format('Y-m');
            $snap = UserMonthlyRankSnapshot::query()
                ->where('user_id', $user->id)
                ->where('month_key', $mk)
                ->first();

            if ($i === 0) {
                $rankId = $target;
            } else {
                if (! $snap) {
                    break;
                }
                $rankId = (int) $snap->rank_id;
            }

            if ($rankId !== $target) {
                break;
            }

            $count++;
            $cursor->subMonth();
        }

        return $count;
    }

    /**
     * @return array{eligible: bool, months_consecutive: int, required_months: int, note: string}
     */
    public function evaluarRachaTresMeses(User $user, string $rankSlug, string $monthKey): array
    {
        $required = (int) config('mlm.leadership.consecutive_months_required', 3);
        $months = $this->mesesConsecutivosMismoRango($user, $monthKey);
        $slugMatch = $user->rank?->slug === $rankSlug;

        return [
            'eligible' => $slugMatch && $months >= $required,
            'months_consecutive' => $months,
            'required_months' => $required,
            'note' => 'Basado en snapshots mensuales y rango actual del usuario.',
        ];
    }
}
