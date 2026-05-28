<?php

namespace App\Http\Controllers\Internal\Sync;

use App\Models\CommissionEvent;
use Illuminate\Http\Request;

class SyncCommissionsController extends BaseSyncController
{
    public function index(Request $request)
    {
        $limit = $this->parseLimit($request, 1000, 5000);
        $updatedSince = $this->parseUpdatedSince($request);
        $cursor = $request->query('cursor');
        $type = $request->query('type'); // opcional: bir|binary|residual|...

        $q = CommissionEvent::query()
            ->select([
                'id',
                'idempotency_key',
                'unique_hash',
                'beneficiary_user_id',
                'origin_user_id',
                'type',
                'level',
                'amount',
                'currency',
                'period_key',
                'period_type',
                'accrual_week_key',
                'wallet_credited_at',
                'order_id',
                'meta',
                'created_at',
                'updated_at',
            ])
            ->orderBy('updated_at')
            ->orderBy('id');

        if ($updatedSince) {
            $q->where('updated_at', '>=', $updatedSince);
        }
        if (is_string($type) && trim($type) !== '' && $type !== 'all') {
            $q->where('type', trim($type));
        }
        $this->applyCursor($q, is_string($cursor) ? $cursor : null);

        $rows = $q->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $slice = $rows->take($limit);
        $last = $slice->last();

        return response()->json([
            'entity' => 'commissions',
            'limit' => $limit,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore ? $this->nextCursorFromRow($last) : null,
            'data' => $slice->map(fn (CommissionEvent $e) => [
                'id' => $e->id,
                'idempotency_key' => $e->idempotency_key,
                'unique_hash' => $e->unique_hash,
                'beneficiary_user_id' => $e->beneficiary_user_id,
                'origin_user_id' => $e->origin_user_id,
                'type' => $e->type,
                'level' => $e->level,
                'amount' => (string) $e->amount,
                'currency' => $e->currency,
                'period_key' => $e->period_key,
                'period_type' => $e->period_type,
                'accrual_week_key' => $e->accrual_week_key,
                'wallet_credited_at' => $e->wallet_credited_at?->toIso8601String(),
                'order_id' => $e->order_id,
                'meta' => $e->meta,
                'created_at' => $e->created_at?->toIso8601String(),
                'updated_at' => $e->updated_at?->toIso8601String(),
            ])->values(),
        ]);
    }
}

