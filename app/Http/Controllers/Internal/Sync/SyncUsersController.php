<?php

namespace App\Http\Controllers\Internal\Sync;

use App\Models\User;
use Illuminate\Http\Request;

class SyncUsersController extends BaseSyncController
{
    public function index(Request $request)
    {
        $limit = $this->parseLimit($request, 1000, 5000);
        $updatedSince = $this->parseUpdatedSince($request);
        $cursor = $request->query('cursor');

        $q = User::query()
            ->select([
                'id',
                'member_code',
                'referral_code',
                'name',
                'email',
                'phone',
                'sponsor_id',
                'mlm_role',
                'account_status',
                'account_type',
                'country_code',
                'country_id',
                'rank_id',
                'is_mlm_qualified',
                'monthly_qualifying_pv',
                'activation_paid_at',
                'created_at',
                'updated_at',
            ])
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
            'entity' => 'users',
            'limit' => $limit,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore ? $this->nextCursorFromRow($last) : null,
            'data' => $slice->map(fn (User $u) => [
                'id' => $u->id,
                'member_code' => $u->member_code,
                'referral_code' => $u->referral_code,
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone,
                'sponsor_id' => $u->sponsor_id,
                'mlm_role' => $u->mlm_role,
                'account_status' => $u->account_status,
                'account_type' => $u->account_type,
                'country_code' => $u->country_code,
                'country_id' => $u->country_id,
                'rank_id' => $u->rank_id,
                'is_mlm_qualified' => (bool) $u->is_mlm_qualified,
                'monthly_qualifying_pv' => (string) ($u->monthly_qualifying_pv ?? '0'),
                'activation_paid_at' => $u->activation_paid_at?->toIso8601String(),
                'created_at' => $u->created_at?->toIso8601String(),
                'updated_at' => $u->updated_at?->toIso8601String(),
            ])->values(),
        ]);
    }
}

