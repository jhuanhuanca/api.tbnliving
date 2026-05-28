<?php

namespace App\Jobs;

use App\Services\CommissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Abona en billetera las comisiones no-binario acumuladas en la semana ISO indicada
 * (devengo según fecha del pedido / liderazgo).
 */
class PayDeferredCommissionsWeeklyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $weekKey)
    {
        $this->onQueue(config('mlm.queues.residual', 'default'));
    }

    public function handle(CommissionService $commissionService): void
    {
        $n = $commissionService->acreditarPendientesPorSemanaIso($this->weekKey);
        Log::info('MLM pago semanal comisiones diferidas', ['week_key' => $this->weekKey, 'commissions_credited' => $n]);
    }
}
