<?php

namespace App\Jobs;

use App\Services\BinaryHybridDailyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Calcula bono binario diario y carry para un día (YYYY-MM-DD).
 */
class CalculateBinaryDailyBonusesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $dayKey)
    {
        $this->onQueue(config('mlm.queues.binary', 'default'));
    }

    public function handle(BinaryHybridDailyService $service): void
    {
        $service->processDaily($this->dayKey);
    }
}

