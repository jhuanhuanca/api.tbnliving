<?php

namespace App\Http\Controllers\Internal\Sync;

use App\Models\Order;
use Illuminate\Http\Request;

class SyncOrdersController extends BaseSyncController
{
    public function index(Request $request)
    {
        $limit = $this->parseLimit($request, 1000, 5000);
        $updatedSince = $this->parseUpdatedSince($request);
        $cursor = $request->query('cursor');
        $estado = $request->query('estado'); // opcional: completado, pendiente, etc.

        $q = Order::query()
            ->select([
                'id',
                'uuid',
                'reference',
                'user_id',
                'tipo',
                'cantidad',
                'total',
                'total_pv',
                'estado',
                'completed_at',
                'payment_method',
                'payment_confirmed_at',
                'created_at',
                'updated_at',
            ])
            ->orderBy('updated_at')
            ->orderBy('id');

        if ($updatedSince) {
            $q->where('updated_at', '>=', $updatedSince);
        }
        if (is_string($estado) && trim($estado) !== '' && $estado !== 'all') {
            $q->where('estado', trim($estado));
        }
        $this->applyCursor($q, is_string($cursor) ? $cursor : null);

        $rows = $q->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $slice = $rows->take($limit);
        $last = $slice->last();

        return response()->json([
            'entity' => 'orders',
            'limit' => $limit,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore ? $this->nextCursorFromRow($last) : null,
            'data' => $slice->map(fn (Order $o) => [
                'id' => $o->id,
                'uuid' => $o->uuid,
                'reference' => $o->reference,
                'user_id' => $o->user_id,
                'tipo' => $o->tipo,
                'cantidad' => $o->cantidad,
                'total' => (string) $o->total,
                'total_pv' => (string) $o->total_pv,
                'estado' => $o->estado,
                'completed_at' => $o->completed_at?->toIso8601String(),
                'payment_method' => $o->payment_method,
                'payment_confirmed_at' => $o->payment_confirmed_at?->toIso8601String(),
                'created_at' => $o->created_at?->toIso8601String(),
                'updated_at' => $o->updated_at?->toIso8601String(),
            ])->values(),
        ]);
    }
}

