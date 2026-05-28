<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

class MlmRedisHealthCommand extends Command
{
    protected $signature = 'mlm:redis-health';

    protected $description = 'Verifica conexiones Redis (default, cache, queue, session) y driver activos';

    public function handle(): int
    {
        $this->info('Drivers activos:');
        $this->line('  CACHE_STORE='.config('cache.default'));
        $this->line('  QUEUE_CONNECTION='.config('queue.default'));
        $this->line('  SESSION_DRIVER='.config('session.driver'));

        foreach (['default', 'cache', 'queue', 'session'] as $name) {
            try {
                $pong = Redis::connection($name)->ping();
                $this->info("  Redis [{$name}] OK: ".(is_string($pong) ? $pong : 'PONG'));
            } catch (\Throwable $e) {
                $this->error("  Redis [{$name}] FAIL: ".$e->getMessage());

                return self::FAILURE;
            }
        }

        $key = 'mlm:health:'.uniqid('', true);
        Cache::put($key, '1', 10);
        if (Cache::get($key) !== '1') {
            $this->error('  Cache write/read FAIL');

            return self::FAILURE;
        }
        Cache::forget($key);
        $this->info('  Cache read/write OK');

        $this->info('  Queue backend: '.Queue::getConnectionName());

        return self::SUCCESS;
    }
}
