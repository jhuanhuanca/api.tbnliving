<?php

namespace App\Services;

use App\Events\WithdrawalStatusChanged;
use App\Jobs\ProcessWithdrawalsJob;
use App\Models\SecurityLog;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Security\SecurityLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WithdrawalService
{
    public function __construct(
        protected WalletService $walletService,
        protected SecurityLogService $securityLog,
    ) {}

    /**
     * Sin monto mínimo; bloquea saldo con retención contable.
     */
    public function solicitar(User $user, string $monto, ?string $notas = null): Withdrawal
    {
        if (bccomp($monto, '0', 2) !== 1) {
            throw new \InvalidArgumentException('Monto inválido');
        }

        $available = $this->walletService->saldoDisponible($user);
        if (bccomp($available, $monto, 2) < 0) {
            throw new \RuntimeException('Saldo insuficiente');
        }

        return DB::transaction(function () use ($user, $monto, $notas) {
            $w = Withdrawal::query()->create([
                'user_id' => $user->id,
                'monto' => $monto,
                'estado' => Withdrawal::ESTADO_PENDIENTE,
                'notas_usuario' => $notas,
                'idempotency_key' => 'wd:req:'.Str::uuid()->toString(),
            ]);

            $this->walletService->registrarRetencion(
                $user,
                $monto,
                $w->id,
                "withdrawal:hold:{$w->id}"
            );

            return $w;
        });
    }

    public function marcarAprobado(Withdrawal $withdrawal, User $admin): void
    {
        if ($withdrawal->estado !== Withdrawal::ESTADO_PENDIENTE) {
            return;
        }

        $withdrawal->update([
            'estado' => Withdrawal::ESTADO_APROBADO,
            'processed_by' => $admin->id,
            'processed_at' => now(),
        ]);

        $this->securityLog->log($withdrawal->user, SecurityLog::ACTION_WITHDRAW_APPROVED, null, null, [
            'withdrawal_id' => $withdrawal->id,
            'admin_id' => $admin->id,
        ]);

        event(new WithdrawalStatusChanged($withdrawal->fresh(), 'approved'));

        ProcessWithdrawalsJob::dispatch($withdrawal->id);
    }

    public function marcarRechazado(Withdrawal $withdrawal, User $admin, ?string $notas = null): void
    {
        if ($withdrawal->estado !== Withdrawal::ESTADO_PENDIENTE) {
            return;
        }

        DB::transaction(function () use ($withdrawal, $admin, $notas) {
            $user = $withdrawal->user;
            $monto = (string) $withdrawal->monto;

            $this->walletService->liberarRetencion(
                $user,
                $monto,
                $withdrawal->id,
                "withdrawal:release:reject:{$withdrawal->id}"
            );

            $withdrawal->update([
                'estado' => Withdrawal::ESTADO_RECHAZADO,
                'processed_by' => $admin->id,
                'processed_at' => now(),
                'notas_admin' => $notas,
                'rejected_reason' => $notas,
            ]);

            $this->securityLog->log($user, SecurityLog::ACTION_WITHDRAW_REJECTED, null, null, [
                'withdrawal_id' => $withdrawal->id,
                'admin_id' => $admin->id,
            ]);

            event(new WithdrawalStatusChanged($withdrawal->fresh(), 'rejected'));
        });
    }

    /**
     * Idempotente: libera retención y registra débito final (pago ejecutado fuera del sistema).
     */
    public function finalizarPago(Withdrawal $withdrawal): void
    {
        $completed = null;

        DB::transaction(function () use ($withdrawal, &$completed) {
            $locked = Withdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->first();
            if (! $locked || $locked->estado !== Withdrawal::ESTADO_APROBADO) {
                return;
            }

            $user = $locked->user;
            $monto = (string) $locked->monto;

            $this->walletService->liberarRetencion(
                $user,
                $monto,
                $locked->id,
                "withdrawal:release:complete:{$locked->id}"
            );

            $this->walletService->debitar(
                $user,
                $monto,
                "withdrawal:debit:{$locked->id}",
                $locked->id,
                ['withdrawal_id' => $locked->id]
            );

            $locked->update([
                'estado' => Withdrawal::ESTADO_COMPLETADO,
            ]);

            $completed = $locked->fresh(['user', 'processor']);
        });

        if ($completed) {
            event(new WithdrawalStatusChanged($completed, 'completed'));
        }
    }
}
