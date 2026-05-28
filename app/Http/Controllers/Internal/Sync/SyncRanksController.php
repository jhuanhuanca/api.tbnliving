<?php

namespace App\Http\Controllers\Internal\Sync;

use App\Models\Rank;
use Illuminate\Http\Request;

class SyncRanksController extends BaseSyncController
{
    public function index(Request $request)
    {
        $limit = $this->parseLimit($request, 500, 5000);
        $updatedSince = $this->parseUpdatedSince($request);
        $cursor = $request->query('cursor');

        $q = Rank::query()
            ->select(['id', 'slug', 'name', 'sort_order', 'max_residual_generations', 'residual_rate_override', 'leadership_rate', 'created_at', 'updated_at'])
            ->orderBy('updated_at')
            ->orderBy('id');

        if ($updatedSince) {
            $q->where('updated_at', '>=', $updatedSince);
        }
        $this->applyCursor($q, is_string($cursor) ? $cursor : null);

        $rows = $q->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $slice = $rows->take($limit);
        $last = $slice->last();

        return response()->json([
            'entity' => 'ranks',
            'limit' => $limit,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore ? $this->nextCursorFromRow($last) : null,
            'data' => $slice->map(fn (Rank $r) => [
                'id' => $r->id,
                'slug' => $r->slug,
                'name' => $r->name,
                'sort_order' => $r->sort_order,
                'max_residual_generations' => $r->max_residual_generations,
                'residual_rate_override' => (string) ($r->residual_rate_override ?? '0'),
                'leadership_rate' => (string) ($r->leadership_rate ?? '0'),
                'created_at' => $r->created_at?->toIso8601String(),
                'updated_at' => $r->updated_at?->toIso8601String(),
            ])->values(),
        ]);
    }
}

