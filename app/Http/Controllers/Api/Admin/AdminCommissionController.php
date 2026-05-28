<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionEvent;
use Illuminate\Http\Request;

class AdminCommissionController extends Controller
{
    public function index(Request $request)
    {
        $type = (string) $request->query('type', 'all');
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');

        $q = CommissionEvent::query()
            ->with('beneficiary:id,name,member_code,email')
            ->orderByDesc('created_at');

        if ($type !== 'all' && $type !== '') {
            $q->where('type', $type);
        }

        if ($from !== '') {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to !== '') {
            $q->whereDate('created_at', '<=', $to);
        }

        $rows = $q->limit(250)->get();
        $total = (string) $rows->sum('amount');

        return response()->json([
            'rows' => $rows->map(function (CommissionEvent $e) {
                return [
                    'id' => $e->id,
                    'user' => $e->beneficiary?->name ?? '—',
                    'member_code' => $e->beneficiary?->member_code,
                    'type' => (string) $e->type,
                    'amount' => (float) $e->amount,
                    'createdAt' => $e->created_at?->toIso8601String(),
                ];
            })->values(),
            'totalAmount' => (float) $total,
        ]);
    }
}

