<?php

namespace App\Jobs;

use App\Services\CommissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Cierre / reconciliación mensual (residual ya se acumula por pedido; aquí se puede auditar o recalcular).
 */
class ProcessResidualCommissionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $monthKey)
    {
        $this->onQueue(config('mlm.queues.residual', 'default'));
    }

    public function handle(CommissionService $commissionService): void
    {
        $commissionService->procesarCierreMensual($this->monthKey);
    }
}
