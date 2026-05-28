<?php

namespace App\Services;

use App\Models\LeadershipBonusPayout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LeadershipBonusPayoutLedger
{
    /**
     * Reserva un payout (append-only) usando UNIQUE(user_id, rank_id, month_key, bonus_type).
     * Si ya existe (retry/race), retorna null para indicar "no procesar".
     *
     * @param  array<string, mixed>  $payload
     */
    public function reserve(array $payload): ?LeadershipBonusPayout
    {
        $payload = array_merge([
            'uuid' => (string) Str::uuid(),
            'status' => 'pending',
        ], $payload);

        return DB::transaction(function () use ($payload) {
            // Intento de insert atómico; si falla por UNIQUE, otro worker ya reservó.
            try {
                return LeadershipBonusPayout::query()->create($payload);
            } catch (\Throwable $e) {
                // Si ya existe, no procesar de nuevo.
                $existing = LeadershipBonusPayout::query()
                    ->where('user_id', $payload['user_id'])
                    ->where('rank_id', $payload['rank_id'])
                    ->where('month_key', $payload['month_key'])
                    ->where('bonus_type', $payload['bonus_type'])
                    ->first();

                return $existing?->status === 'pending' ? null : null;
            }
        });
    }

    /**
     * Marca como processed. No borra ni sobrescribe historial; solo transiciona estado.
     *
     * @param  array<string, mixed>  $extra
     */
    public function markProcessed(LeadershipBonusPayout $payout, array $extra = []): void
    {
        $payout->forceFill(array_merge($extra, [
            'status' => 'processed',
            'processed_at' => now(),
        ]))->save();
    }

    public function markFailed(LeadershipBonusPayout $payout, ?string $reason = null): void
    {
        $meta = is_array($payout->metadata) ? $payout->metadata : [];
        if ($reason) {
            $meta['failed_reason'] = $reason;
        }
        $payout->forceFill([
            'status' => 'failed',
            'metadata' => $meta,
        ])->save();
    }
}

