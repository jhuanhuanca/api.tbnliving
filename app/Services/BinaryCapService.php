<?php

namespace App\Services;

use App\Models\CommissionEvent;
use Carbon\Carbon;

/**
 * Tope configurable de pagos binarios (diario / semanal ISO / mensual) sobre comisiones ya acreditadas en el periodo.
 */
class BinaryCapService
{
    /**
     * @return array{amount: string, meta: array<string, mixed>}
     */
    public function aplicarTopePagoBinario(int $userId, string $payoutBob, string $binaryWeekKey): array
    {
        $cfg = config('mlm.binary.cap', []);
        if (empty($cfg['enabled'])) {
            return [
                'amount' => bcadd($payoutBob, '0', 2),
                'meta' => ['cap_period_key' => null, 'capped' => false],
            ];
        }

        $mode = (string) ($cfg['mode'] ?? 'weekly');
        $max = bcadd((string) ($cfg['max_bob'] ?? '999999999999'), '0', 2);
        $capKey = $this->periodoTope($mode, $binaryWeekKey);

        $accrued = $this->sumBinaryPaidInCapPeriod($userId, $capKey);
        $room = bcsub($max, $accrued, 2);
        if (bccomp($room, '0', 2) !== 1) {
            return [
                'amount' => '0.00',
                'meta' => [
                    'capped' => true,
                    'cap_period_key' => $capKey,
                    'cap_mode' => $mode,
                    'accrued_before' => $accrued,
                    'requested' => $payoutBob,
                ],
            ];
        }

        $final = bccomp($payoutBob, $room, 2) === 1 ? $room : $payoutBob;
        $final = bcadd($final, '0', 2);

        return [
            'amount' => $final,
            'meta' => [
                'capped' => bccomp($final, $payoutBob, 2) !== 0,
                'cap_period_key' => $capKey,
                'cap_mode' => $mode,
                'accrued_before' => $accrued,
                'requested' => $payoutBob,
            ],
        ];
    }

    protected function periodoTope(string $mode, string $binaryWeekKey): string
    {
        return match ($mode) {
            'daily' => Carbon::now()->format('Y-m-d'),
            'monthly' => Carbon::now()->format('Y-m'),
            default => $binaryWeekKey,
        };
    }

    protected function sumBinaryPaidInCapPeriod(int $userId, string $capKey): string
    {
        $sum = CommissionEvent::query()
            ->where('beneficiary_user_id', $userId)
            ->where('type', 'binary')
            ->where('meta->cap_period_key', $capKey)
            ->sum('amount');

        return bcadd((string) $sum, '0', 2);
    }
}
