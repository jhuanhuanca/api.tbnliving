<?php

namespace App\Http\Controllers\Internal\Sync;

use App\Models\Product;
use App\Support\ProductImageStorage;
use Illuminate\Http\Request;

class SyncProductsController extends BaseSyncController
{
    public function index(Request $request)
    {
        $limit = $this->parseLimit($request, 1000, 5000);
        $updatedSince = $this->parseUpdatedSince($request);
        $cursor = $request->query('cursor');
        $estado = $request->query('estado');

        $q = Product::query()
            ->select([
                'id',
                'name',
                'description',
                'price',
                'price_cliente_preferente',
                'stock',
                'image_url',
                'category_id',
                'pv_points',
                'estado',
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
            'entity' => 'products',
            'limit' => $limit,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore ? $this->nextCursorFromRow($last) : null,
            'data' => $slice->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'price' => (string) $p->price,
                'price_cliente_preferente' => (string) $p->price_cliente_preferente,
                'stock' => $p->availableStock(),
                'stock_available' => $p->availableStock(),
                'in_stock' => $p->isInStock(),
                'image_url' => $p->resolveImageUrl(),
                'has_stored_image' => ProductImageStorage::existsFor($p),
                'category_id' => $p->category_id,
                'pv_points' => (string) ($p->pv_points ?? '0'),
                'estado' => $p->estado,
                'created_at' => $p->created_at?->toIso8601String(),
                'updated_at' => $p->updated_at?->toIso8601String(),
            ])->values(),
        ]);
    }
}

