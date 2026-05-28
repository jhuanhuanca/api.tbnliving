<?php

namespace App\Services;

use App\Models\LeadershipRankRequalification;
use Illuminate\Support\Facades\DB;

class LeadershipRequalificationService
{
    public const MAX_ADDITIONAL_REQUALIFICATIONS = 2;
    public const MAX_TOTAL_PAYOUTS_PER_RANK = 3; // 1 inicial + 2 requal

    /**
     * Estado de re-calificación por rango para un usuario y un mes.
     *
     * @return array{
     *   leadership_requalification_count: int,
     *   remaining_requalifications: int,
     *   leadership_bonus_paid_count: int,
     *   last_paid_month_key: ?string,
     *   leadership_bonus_eligibility: bool,
     *   status: string
     * }
     */
    public function statusFor(int $userId, int $rankId, string $monthKey): array
    {
        $row = LeadershipRankRequalification::query()
            ->where('user_id', $userId)
            ->where('rank_id', $rankId)
            ->first();

        $requals = (int) ($row?->requalification_count ?? 0);
        $paid = (int) ($row?->leadership_bonus_paid_count ?? 0);
        $remaining = max(0, self::MAX_ADDITIONAL_REQUALIFICATIONS - $requals);
        $lastPaid = $row?->last_paid_month_key ? (string) $row->last_paid_month_key : null;
        $status = (string) ($row?->status ?? 'active');

        $eligible = $status === 'active'
            && $paid < self::MAX_TOTAL_PAYOUTS_PER_RANK
            && $lastPaid !== $monthKey;

        return [
            'leadership_requalification_count' => $requals,
            'remaining_requalifications' => $remaining,
            'leadership_bonus_paid_count' => $paid,
            'last_paid_month_key' => $lastPaid,
            'leadership_bonus_eligibility' => $eligible,
            'status' => $status,
        ];
    }

    /**
     * Registra (idempotente) que se pagó liderazgo en un mes para ese rango.
     * Debe llamarse solo después de validar PV/calificación.
     */
    public function markPaid(int $userId, int $rankId, string $monthKey): void
    {
        DB::transaction(function () use ($userId, $rankId, $monthKey) {
            $row = LeadershipRankRequalification::query()
                ->where('user_id', $userId)
                ->where('rank_id', $rankId)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                LeadershipRankRequalification::query()->create([
                    'user_id' => $userId,
                    'rank_id' => $rankId,
                    'initial_qualification_month_key' => $monthKey,
                    'initial_qualification_at' => now(),
                    'requalification_count' => 0,
                    'last_requalified_at' => null,
                    'last_paid_month_key' => $monthKey,
                    'leadership_bonus_paid_count' => 1,
                    'status' => 'active',
                    'meta' => [],
                ]);
                return;
            }

            if ((string) $row->status !== 'active') {
                return;
            }

            $paid = (int) ($row->leadership_bonus_paid_count ?? 0);
            $requals = (int) ($row->requalification_count ?? 0);
            $lastPaid = $row->last_paid_month_key ? (string) $row->last_paid_month_key : null;

            // Idempotencia mensual
            if ($lastPaid === $monthKey) {
                return;
            }

            if ($paid >= self::MAX_TOTAL_PAYOUTS_PER_RANK) {
                $row->forceFill(['status' => 'capped'])->save();
                return;
            }

            // Pagos posteriores al inicial cuentan como requal.
            $isInitial = $paid === 0;
            $newPaid = $paid + 1;
            $newRequals = $isInitial ? 0 : min(self::MAX_ADDITIONAL_REQUALIFICATIONS, $requals + 1);

            if (! $isInitial && $requals >= self::MAX_ADDITIONAL_REQUALIFICATIONS) {
                $row->forceFill(['status' => 'capped'])->save();
                return;
            }

            $row->forceFill([
                'leadership_bonus_paid_count' => $newPaid,
                'requalification_count' => $newRequals,
                'last_requalified_at' => $isInitial ? null : now(),
                'last_paid_month_key' => $monthKey,
                'status' => $newPaid >= self::MAX_TOTAL_PAYOUTS_PER_RANK ? 'capped' : 'active',
            ])->save();
        });
    }
}

