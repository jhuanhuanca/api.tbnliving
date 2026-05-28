<?php

namespace App\Services\Cache;

use App\Support\Cache\MlmCacheKeys;
use Closure;
use Illuminate\Support\Facades\Cache;

class AdminDashboardCacheService
{
    public function remember(Closure $builder): array
    {
        if (! config('mlm_redis.enabled', true)) {
            return $builder();
        }

        $ttl = (int) config('mlm_redis.ttl.admin_dashboard', 120);

        return Cache::remember(MlmCacheKeys::adminDashboard(), $ttl, $builder);
    }
}
