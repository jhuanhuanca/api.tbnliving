<?php

namespace App\Services;

use App\Models\CommissionEvent;
use App\Models\LeadershipBonusPayout;
use Illuminate\Support\Facades\DB;

/**
 * Agregados mensuales de bono de liderazgo desde commission_events (type = leadership).
 */
class LeadershipService
{
    /**
     * @return array{month_key: string, total_amount: string, events_count: int, by_beneficiary: array<int, array{user_id: int, total: string, events: int}>}
     */
    public function monthlySummary(string $monthKey): array
    {
        $base = CommissionEvent::query()
            ->where('type', 'leadership')
            ->where('period_key', $monthKey)
            ->where('period_type', 'monthly');

        $eventsCount = (clone $base)->count();
        $totalAmount = (string) (clone $base)->sum('amount');

        $byBeneficiary = (clone $base)
            ->select('beneficiary_user_id', DB::raw('count(*) as events'), DB::raw('coalesce(sum(amount), 0) as total'))
            ->groupBy('beneficiary_user_id')
            ->orderByDesc('total')
            ->limit(50)
            ->get()
            ->map(fn ($row) => [
                'user_id' => (int) $row->beneficiary_user_id,
                'total' => (string) $row->total,
                'events' => (int) $row->events,
            ])
            ->values()
            ->all();

        return [
            'month_key' => $monthKey,
            'total_amount' => $totalAmount,
            'events_count' => $eventsCount,
            'by_beneficiary' => $byBeneficiary,
        ];
    }

    /**
     * Ledger append-only: summary desde leadership_bonus_payouts (auditoría financiera).
     *
     * @return array{month_key: string, total_amount: string, payouts_count: int, processed_count: int, by_user: array<int, array{user_id: int, total: string, payouts: int}>}
     */
    public function monthlyLedgerSummary(string $monthKey): array
    {
        $base = LeadershipBonusPayout::query()
            ->where('bonus_type', 'leadership')
            ->where('month_key', $monthKey);

        $payoutsCount = (clone $base)->count();
        $processedCount = (clone $base)->where('status', 'processed')->count();
        $totalAmount = (string) (clone $base)->where('status', 'processed')->sum('amount');

        $byUser = (clone $base)
            ->where('status', 'processed')
            ->select('user_id', DB::raw('count(*) as payouts'), DB::raw('coalesce(sum(amount), 0) as total'))
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->limit(50)
            ->get()
            ->map(fn ($row) => [
                'user_id' => (int) $row->user_id,
                'total' => (string) $row->total,
                'payouts' => (int) $row->payouts,
            ])
            ->values()
            ->all();

        return [
            'month_key' => $monthKey,
            'total_amount' => $totalAmount,
            'payouts_count' => $payoutsCount,
            'processed_count' => $processedCount,
            'by_user' => $byUser,
        ];
    }
}
