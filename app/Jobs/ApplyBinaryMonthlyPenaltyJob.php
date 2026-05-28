<?php

namespace App\Jobs;

use App\Services\BinaryHybridDailyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Aplica penalización mensual al acumulado no pagado del binario híbrido para un mes YYYY-MM.
 */
class ApplyBinaryMonthlyPenaltyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $monthKey)
    {
        $this->onQueue(config('mlm.queues.binary', 'default'));
    }

    public function handle(BinaryHybridDailyService $service): void
    {
        $service->applyMonthlyPenalty($this->monthKey);
    }
}

