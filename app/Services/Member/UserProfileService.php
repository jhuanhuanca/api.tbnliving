<?php

namespace App\Services\Member;

use App\Models\Rank;
use App\Models\User;
use App\Services\CareerRankService;
use App\Services\UserQualificationService;
use Illuminate\Http\Request;

/**
 * Construye el payload JSON del perfil del socio autenticado.
 * Usado por GET /me y GET /api/v1/auth/me.
 */
class UserProfileService
{
    public function __construct(
        protected CareerRankService $careerRankService,
        protected UserQualificationService $qualificationService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildFromRequest(Request $request): array
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return [];
        }

        return $this->buildForUser($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildForUser(User $user): array
    {
        $user = $user->loadMissing('rank', 'sponsor', 'registrationPackage', 'referrals.rank', 'country');
        $user = $this->qualificationService->syncMonthlyQualificationIfStale($user);

        $computedSlug = $this->careerRankService->computeHighestEligibleRankSlug($user);
        $computedRank = Rank::query()->where('slug', $computedSlug)->first();
        $computedName = $computedRank?->name ?? $computedSlug;

        $display = $this->displayRankTuple($user);

        $payload = $user->toArray();
        $payload['computed_rank'] = [
            'slug' => $computedSlug,
            'name' => $computedName,
        ];
        $payload['rank_name'] = $display['name'];
        $payload['country'] = $user->country?->toApiArray();

        return $payload;
    }

    /**
     * @return array{id: ?int, name: string, slug: string}
     */
    protected function displayRankTuple(User $user): array
    {
        $user->loadMissing('rank', 'registrationPackage', 'referrals.rank');
        $slug = $this->careerRankService->displayRankSlug($user);
        $rank = Rank::query()->where('slug', $slug)->first();

        return [
            'id' => $rank?->id,
            'name' => (string) ($rank?->name ?? $slug),
            'slug' => $slug,
        ];
    }
}
