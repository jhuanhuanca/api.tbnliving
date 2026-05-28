<?php

namespace App\Http\Controllers\Internal\Sync;

use App\Models\OrderItem;
use Illuminate\Http\Request;

class SyncOrderItemsController extends BaseSyncController
{
    public function index(Request $request)
    {
        $limit = $this->parseLimit($request, 2000, 5000);
        $updatedSince = $this->parseUpdatedSince($request);
        $cursor = $request->query('cursor');

        $orderId = $request->query('order_id');
        $productId = $request->query('product_id');
        $onlyProducts = $request->query('only_products'); // true|false

        $q = OrderItem::query()
            ->select([
                'id',
                'order_id',
                'product_id',
                'package_id',
                'cantidad',
                'precio_unitario',
                'precio_total',
                'pv_points',
                'commissionable_pv',
                'commissionable_amount',
                'line_currency',
                'fx_rate_to_wallet',
                'meta',
                'created_at',
                'updated_at',
            ])
            ->orderBy('updated_at')
            ->orderBy('id');

        if ($updatedSince) {
            $q->where('updated_at', '>=', $updatedSince);
        }
        if (is_numeric($orderId)) {
            $q->where('order_id', (int) $orderId);
        }
        if (is_numeric($productId)) {
            $q->where('product_id', (int) $productId);
        }
        if (is_string($onlyProducts) && in_array(strtolower($onlyProducts), ['1', 'true', 'yes'], true)) {
            $q->whereNotNull('product_id');
        }

        $this->applyCursor($q, is_string($cursor) ? $cursor : null);

        $rows = $q->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $slice = $rows->take($limit);
        $last = $slice->last();

        return response()->json([
            'entity' => 'order_items',
            'limit' => $limit,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore ? $this->nextCursorFromRow($last) : null,
            'data' => $slice->map(fn (OrderItem $i) => [
                'id' => $i->id,
                'order_id' => $i->order_id,
                'product_id' => $i->product_id,
                'package_id' => $i->package_id,
                'cantidad' => (int) ($i->cantidad ?? 0),
                'precio_unitario' => (string) ($i->precio_unitario ?? '0'),
                'precio_total' => (string) ($i->precio_total ?? '0'),
                'pv_points' => (string) ($i->pv_points ?? '0'),
                'commissionable_pv' => $i->commissionable_pv !== null ? (string) $i->commissionable_pv : null,
                'commissionable_amount' => $i->commissionable_amount !== null ? (string) $i->commissionable_amount : null,
                'line_currency' => $i->line_currency,
                'fx_rate_to_wallet' => $i->fx_rate_to_wallet !== null ? (string) $i->fx_rate_to_wallet : null,
                'meta' => $i->meta,
                'created_at' => $i->created_at?->toIso8601String(),
                'updated_at' => $i->updated_at?->toIso8601String(),
            ])->values(),
        ]);
    }
}

