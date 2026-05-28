<?php

namespace App\Support\Redis;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Locks distribuidos (driver cache → Redis en producción).
 */
class RedisLockService
{
    public function walletUser(int $userId, Closure $callback, ?int $seconds = null): mixed
    {
        return $this->run('wallet:user:'.$userId, $seconds ?? (int) config('mlm_redis.locks.wallet_seconds', 15), $callback);
    }

    public function withdrawal(int $withdrawalId, Closure $callback, ?int $seconds = null): mixed
    {
        return $this->run('withdrawal:'.$withdrawalId, $seconds ?? (int) config('mlm_redis.locks.withdrawal_seconds', 30), $callback);
    }

    public function orderPayment(int $orderId, Closure $callback, ?int $seconds = null): mixed
    {
        return $this->run('order:payment:'.$orderId, $seconds ?? (int) config('mlm_redis.locks.order_payment_seconds', 20), $callback);
    }

    protected function run(string $name, int $seconds, Closure $callback): mixed
    {
        $lock = Cache::lock($name, $seconds);

        try {
            return $lock->block($seconds, $callback);
        } catch (LockTimeoutException $e) {
            Log::warning('redis.lock.timeout', ['name' => $name, 'seconds' => $seconds]);

            throw $e;
        }
    }
}
