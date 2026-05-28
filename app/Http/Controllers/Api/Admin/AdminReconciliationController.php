<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionEvent;
use App\Models\PeriodClosure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminReconciliationController extends Controller
{
    public function periodClosures(Request $request)
    {
        $perPage = (int) min($request->query('per_page', 50), 200);

        return response()->json(
            PeriodClosure::query()
                ->orderByDesc('id')
                ->paginate($perPage)
        );
    }

    /**
     * Agregados por tipo y period_key para cuadre contable / auditoría.
     */
    public function commissionSummary(Request $request)
    {
        $data = $request->validate([
            'period_key' => 'nullable|string|max:32',
            'period_type' => 'nullable|string|max:32',
        ]);

        $q = CommissionEvent::query()
            ->when($data['period_key'] ?? null, fn ($b, $pk) => $b->where('period_key', $pk))
            ->when($data['period_type'] ?? null, fn ($b, $pt) => $b->where('period_type', $pt));

        $rows = $q
            ->select([
                'type',
                'period_key',
                'period_type',
                DB::raw('count(*) as events_count'),
                DB::raw('coalesce(sum(amount), 0) as total_amount'),
            ])
            ->groupBy('type', 'period_key', 'period_type')
            ->orderBy('period_key')
            ->orderBy('type')
            ->get();

        return response()->json(['data' => $rows]);
    }
}
