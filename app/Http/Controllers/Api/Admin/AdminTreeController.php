<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BinaryPlacement;
use App\Models\Rank;
use App\Models\User;
use App\Services\CareerRankService;
use Illuminate\Http\Request;

class AdminTreeController extends Controller
{
    public function search(Request $request, CareerRankService $careerRankService)
    {
        $q = trim((string) $request->query('q', ''));
        if ($q === '' || mb_strlen($q) < 2) {
            return response()->json([
                'ok' => false,
                'message' => 'Escribe al menos 2 caracteres (código o correo).',
            ], 422);
        }

        $like = '%'.$q.'%';
        $users = User::query()
            ->where(function ($w) {
                $w->whereNull('account_type')->orWhere('account_type', 'member');
            })
            ->where(function ($w) use ($q, $like) {
                $w->where('email', 'like', $like)
                    ->orWhere('member_code', 'like', $like)
                    ->orWhere('referral_code', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('member_code', $q)
                    ->orWhere('referral_code', $q)
                    ->orWhere('email', $q);
            })
            ->with(['rank:id,name,slug'])
            ->orderByRaw("case when account_status = 'active' then 0 when account_status = 'pending' then 1 else 2 end")
            ->orderBy('name')
            ->limit(20)
            ->get();

        $rankNamesBySlug = Rank::query()->pluck('name', 'slug')->all();
        $results = $users->map(function (User $u) use ($careerRankService, $rankNamesBySlug) {
            $slug = $careerRankService->displayRankSlug($u);
            $rankName = (string) ($rankNamesBySlug[$slug] ?? $slug);
            $inTree = BinaryPlacement::query()->where('user_id', $u->id)->exists();

            return [
                'id' => (int) $u->id,
                'name' => (string) $u->name,
                'email' => (string) $u->email,
                'member_code' => (string) ($u->member_code ?? ''),
                'referral_code' => (string) ($u->referral_code ?? ''),
                'is_active' => (bool) $u->is_mlm_qualified,
                'rank' => $rankName !== '' ? $rankName : '—',
                'in_tree' => $inTree,
            ];
        })->values();

        return response()->json([
            'ok' => true,
            'q' => $q,
            'results' => $results,
        ]);
    }

    public function show(int $userId, CareerRankService $careerRankService)
    {
        $u = User::query()
            ->with(['rank', 'registrationPackage', 'referrals.rank', 'sponsor:id,name,member_code,email'])
            ->findOrFail($userId);

        return response()->json([
            'ok' => true,
            'user' => $this->userDetailDto($u, $careerRankService),
        ]);
    }

    public function root(Request $request, CareerRankService $careerRankService)
    {
        $rootId = (int) ($request->query('root_user_id') ?? 0);
        if ($rootId <= 0) {
            $rootId = (int) User::query()
                ->where(function ($w) {
                    $w->whereNull('account_type')->orWhere('account_type', 'member');
                })
                ->whereExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('binary_placements')
                        ->whereColumn('binary_placements.user_id', 'users.id');
                })
                ->orderBy('id')
                ->value('id');
        }

        if ($rootId <= 0) {
            return response()->json([
                'ok' => false,
                'message' => 'No hay usuarios con colocación binaria en el sistema.',
            ], 404);
        }

        $u = User::query()
            ->with(['rank', 'registrationPackage', 'referrals.rank', 'sponsor:id,name,member_code,email'])
            ->findOrFail($rootId);

        $node = $this->binaryNodeWithLegs($u, $careerRankService, loadChildren: false);

        return response()->json([
            'ok' => true,
            'node' => $node,
            'user' => $this->userDetailDto($u, $careerRankService),
        ]);
    }

    public function children(Request $request, int $nodeId, CareerRankService $careerRankService)
    {
        $parent = User::query()
            ->with(['rank', 'registrationPackage', 'referrals.rank'])
            ->findOrFail($nodeId);

        $node = $this->binaryNodeWithLegs($parent, $careerRankService, loadChildren: true);

        return response()->json([
            'ok' => true,
            'node' => $node,
        ]);
    }

    /**
     * @return array<int, array|null>
     */
    protected function legChildren(int $parentUserId, CareerRankService $careerRankService): array
    {
        $leftPl = BinaryPlacement::query()
            ->where('parent_user_id', $parentUserId)
            ->where('leg_position', BinaryPlacement::LEG_LEFT)
            ->first();
        $rightPl = BinaryPlacement::query()
            ->where('parent_user_id', $parentUserId)
            ->where('leg_position', BinaryPlacement::LEG_RIGHT)
            ->first();

        $leftUser = $leftPl
            ? User::query()->with(['rank', 'registrationPackage', 'referrals.rank'])->find($leftPl->user_id)
            : null;
        $rightUser = $rightPl
            ? User::query()->with(['rank', 'registrationPackage', 'referrals.rank'])->find($rightPl->user_id)
            : null;

        return [
            $leftUser ? $this->binaryNodeDto($leftUser, $careerRankService) : null,
            $rightUser ? $this->binaryNodeDto($rightUser, $careerRankService) : null,
        ];
    }

    protected function binaryNodeWithLegs(User $u, CareerRankService $careerRankService, bool $loadChildren): array
    {
        $node = $this->binaryNodeDto($u, $careerRankService);
        if ($loadChildren) {
            [$left, $right] = $this->legChildren((int) $u->id, $careerRankService);
            $node['left'] = $left;
            $node['right'] = $right;
        }

        return $node;
    }

    protected function binaryNodeDto(User $u, CareerRankService $careerRankService): array
    {
        $rankNamesBySlug = Rank::query()->pluck('name', 'slug')->all();
        $u->loadMissing('rank', 'registrationPackage', 'referrals.rank');
        $slug = $careerRankService->displayRankSlug($u);
        $rankName = (string) ($rankNamesBySlug[$slug] ?? $slug);
        $hasChildren = BinaryPlacement::query()->where('parent_user_id', $u->id)->exists();
        $placement = BinaryPlacement::query()->where('user_id', $u->id)->first();

        return [
            'id' => (int) $u->id,
            'name' => (string) $u->name,
            'code' => (string) ($u->member_code ?? $u->referral_code ?? $u->id),
            'email' => (string) ($u->email ?? ''),
            'phone' => (string) ($u->phone ?? ''),
            'rank' => $rankName !== '' ? $rankName : '—',
            'rank_slug' => $slug,
            'is_active' => (bool) $u->is_mlm_qualified,
            'active' => (bool) $u->is_mlm_qualified,
            'monthly_qualifying_pv' => (string) ($u->monthly_qualifying_pv ?? '0'),
            'account_status' => (string) ($u->account_status ?? ''),
            'leg' => $placement?->leg_position,
            'has_children' => $hasChildren,
            'lazy' => $hasChildren,
            'left' => null,
            'right' => null,
            'children' => [],
        ];
    }

    protected function userDetailDto(User $u, CareerRankService $careerRankService): array
    {
        $rankNamesBySlug = Rank::query()->pluck('name', 'slug')->all();
        $u->loadMissing('rank', 'registrationPackage', 'referrals.rank', 'sponsor');
        $slug = $careerRankService->displayRankSlug($u);
        $rankName = (string) ($rankNamesBySlug[$slug] ?? $slug);
        $placement = BinaryPlacement::query()->where('user_id', $u->id)->first();
        $parentUser = $placement?->parent_user_id
            ? User::query()->find($placement->parent_user_id)
            : null;

        return [
            'id' => (int) $u->id,
            'name' => (string) $u->name,
            'email' => (string) $u->email,
            'phone' => (string) ($u->phone ?? ''),
            'document_id' => (string) ($u->document_id ?? ''),
            'member_code' => (string) ($u->member_code ?? ''),
            'referral_code' => (string) ($u->referral_code ?? ''),
            'rank' => $rankName !== '' ? $rankName : '—',
            'rank_slug' => $slug,
            'is_active' => (bool) $u->is_mlm_qualified,
            'monthly_qualifying_pv' => (string) ($u->monthly_qualifying_pv ?? '0'),
            'account_status' => (string) ($u->account_status ?? ''),
            'activation_paid_at' => optional($u->activation_paid_at)?->toIso8601String(),
            'email_verified_at' => optional($u->email_verified_at)?->toIso8601String(),
            'created_at' => optional($u->created_at)?->toIso8601String(),
            'sponsor' => $u->sponsor ? [
                'id' => (int) $u->sponsor->id,
                'name' => (string) $u->sponsor->name,
                'member_code' => (string) ($u->sponsor->member_code ?? ''),
            ] : null,
            'binary_parent' => $parentUser ? [
                'id' => (int) $parentUser->id,
                'name' => (string) $parentUser->name,
                'member_code' => (string) ($parentUser->member_code ?? ''),
            ] : null,
            'binary_leg' => $placement?->leg_position,
            'has_binary_children' => BinaryPlacement::query()->where('parent_user_id', $u->id)->exists(),
        ];
    }
}
