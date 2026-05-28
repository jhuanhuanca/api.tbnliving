<?php

namespace App\Jobs;

use App\Services\BinaryHybridDailyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Cierre semanal híbrido: suma bonos diarios, aplica cap, registra pago y saldo no pagado.
 */
class CalculateBinaryWeeklyHybridPayoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $weekKey)
    {
        $this->onQueue(config('mlm.queues.binary', 'default'));
    }

    public function handle(BinaryHybridDailyService $service): void
    {
        $service->processWeekly($this->weekKey);
    }
}

