<?php

namespace App\Services\Cache;

use App\Models\User;
use App\Services\WalletService;
use App\Support\Cache\MlmCacheKeys;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Lectura cacheada de saldo (TTL corto). Siempre invalidar tras mutación wallet.
 */
class WalletBalanceCacheService
{
    public function __construct(
        protected WalletService $walletService,
        protected MlmCacheInvalidator $invalidator,
    ) {}

    public function saldoDisponible(User $user, bool $fresh = false): string
    {
        if ($fresh || ! config('mlm_redis.enabled', true)) {
            return $this->walletService->saldoDisponible($user);
        }

        $ttl = (int) config('mlm_redis.ttl.wallet_balance', 30);
        $key = MlmCacheKeys::walletBalance((int) $user->id);

        return Cache::remember($key, $ttl, fn () => $this->walletService->saldoDisponible($user));
    }

    public function forget(User $user): void
    {
        $this->invalidator->forgetWalletUser((int) $user->id);
    }
}
