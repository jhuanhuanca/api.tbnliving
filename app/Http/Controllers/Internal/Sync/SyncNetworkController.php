<?php

namespace App\Http\Controllers\Internal\Sync;

use App\Models\BinaryPlacement;
use Illuminate\Http\Request;

class SyncNetworkController extends BaseSyncController
{
    public function index(Request $request)
    {
        $limit = $this->parseLimit($request, 2000, 10000);
        $updatedSince = $this->parseUpdatedSince($request);
        $cursor = $request->query('cursor');

        $q = BinaryPlacement::query()
            ->select(['id', 'user_id', 'parent_user_id', 'leg_position', 'created_at', 'updated_at'])
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
            'entity' => 'network',
            'limit' => $limit,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore ? $this->nextCursorFromRow($last) : null,
            'data' => $slice->map(fn (BinaryPlacement $p) => [
                'id' => $p->id,
                'user_id' => $p->user_id,
                'parent_user_id' => $p->parent_user_id,
                'leg_position' => $p->leg_position,
                'created_at' => $p->created_at?->toIso8601String(),
                'updated_at' => $p->updated_at?->toIso8601String(),
            ])->values(),
        ]);
    }
}

