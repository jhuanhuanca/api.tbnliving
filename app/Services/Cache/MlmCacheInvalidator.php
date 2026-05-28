<?php

namespace App\Services\Cache;

use App\Support\Cache\MlmCacheKeys;
use Illuminate\Support\Facades\Cache;

class MlmCacheInvalidator
{
    public function forgetAdminDashboard(): void
    {
        Cache::forget(MlmCacheKeys::adminDashboard());
    }

    public function forgetWalletUser(int $userId): void
    {
        Cache::forget(MlmCacheKeys::walletBalance($userId));
    }

    public function forgetBinaryUser(int $userId): void
    {
        Cache::forget(MlmCacheKeys::binaryAncestors($userId));
    }

    public function forgetLeaderboard(?string $monthKey = null): void
    {
        $monthKey = $monthKey ?? now()->format('Y-m');
        Cache::forget(MlmCacheKeys::leaderboardMonthly($monthKey));
    }

    public function onOrderCompleted(?int $userId = null): void
    {
        $this->forgetAdminDashboard();
        $this->forgetLeaderboard();
        if ($userId) {
            $this->forgetWalletUser($userId);
            $this->forgetBinaryUser($userId);
        }
    }

    public function onWithdrawalChanged(?int $userId = null): void
    {
        $this->forgetAdminDashboard();
        if ($userId) {
            $this->forgetWalletUser($userId);
        }
    }

    public function onWalletMutation(int $userId): void
    {
        $this->forgetWalletUser($userId);
    }

    public function onRankReevaluation(): void
    {
        $this->forgetAdminDashboard();
        Cache::forget('mlm:rank_sort_by_slug');
        $this->forgetLeaderboard();
    }
}
