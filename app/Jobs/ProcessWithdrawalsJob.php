<?php

namespace App\Jobs;

use App\Models\Withdrawal;
use App\Services\WithdrawalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Procesa un retiro aprobado (débito + liberación de retención) de forma asíncrona e idempotente.
 */
class ProcessWithdrawalsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $withdrawalId)
    {
        $this->onQueue(config('mlm.queues.withdrawals', 'default'));
    }

    public function handle(WithdrawalService $withdrawalService): void
    {
        $w = Withdrawal::query()->find($this->withdrawalId);
        if (! $w || $w->estado !== Withdrawal::ESTADO_APROBADO) {
            return;
        }
        $withdrawalService->finalizarPago($w);
    }
}
