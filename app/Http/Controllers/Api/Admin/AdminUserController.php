<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Rank;
use App\Models\User;
use App\Services\CareerRankService;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function index(Request $request, CareerRankService $careerRankService)
    {
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all'); // all|active|inactive|pending
        $perPage = (int) $request->query('per_page', $request->query('pageSize', 25));
        $perPage = max(5, min(100, $perPage));

        $query = User::query()
            ->where(function ($w) {
                $w->whereNull('account_type')->orWhere('account_type', 'member');
            })
            ->with(['rank:id,name,slug', 'registrationPackage:id,pv_points', 'referrals.rank:id,sort_order,slug', 'referrals:id,sponsor_id'])
            ->select(['id', 'name', 'email', 'member_code', 'account_status', 'is_mlm_qualified', 'monthly_qualifying_pv', 'created_at', 'rank_id', 'registration_package_id'])
            ->orderByDesc('id');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('member_code', 'like', "%{$q}%")
                    ->orWhere('referral_code', 'like', "%{$q}%");
            });
        }

        if ($status !== 'all') {
            if (in_array($status, ['active', 'pending', 'inactive'], true)) {
                $query->where('account_status', $status);
            }
        }

        $rankNamesBySlug = Rank::query()->pluck('name', 'slug')->all();

        $page = $query->paginate($perPage);
        $page->getCollection()->transform(function (User $u) use ($careerRankService, $rankNamesBySlug) {
            $u->loadMissing('referrals.rank');
            $slug = $careerRankService->displayRankSlug($u);
            $rankName = (string) ($rankNamesBySlug[$slug] ?? $slug);

            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'member_code' => $u->member_code,
                'rank' => $rankName !== '' ? $rankName : '—',
                'rank_slug' => $slug,
                'active' => (string) $u->account_status === 'active',
                'is_mlm_qualified' => (bool) $u->is_mlm_qualified,
                'status' => (string) $u->account_status,
                'monthly_qualifying_pv' => (string) ($u->monthly_qualifying_pv ?? '0'),
                'created_at' => optional($u->created_at)?->toIso8601String(),
            ];
        });

        return response()->json([
            'rows' => $page->items(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'pageSize' => $page->perPage(),
        ]);
    }
}

