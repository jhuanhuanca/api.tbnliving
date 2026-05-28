<?php

namespace App\Services;

use App\Models\MlmPeriod;
use App\Models\Order;
use Carbon\Carbon;
use RuntimeException;

/**
 * Periodos contables MLM: bloqueo de reprocesos cuando el periodo está cerrado.
 */
class PeriodService
{
    public function asegurarPeriodoAbiertoParaOrden(Order $order): void
    {
        $at = $order->completed_at ? Carbon::parse($order->completed_at) : now();
        $this->asegurarMensualAbierto($at);
    }

    public function asegurarMensualAbierto(Carbon $at): void
    {
        $key = $at->format('Y-m');
        if ($this->estaCerrado('monthly', $key)) {
            throw new RuntimeException("Periodo mensual cerrado ({$key}); no se procesan comisiones.");
        }
    }

    public function estaCerrado(string $periodType, string $periodKey): bool
    {
        return MlmPeriod::query()
            ->where('period_type', $periodType)
            ->where('period_key', $periodKey)
            ->where('status', MlmPeriod::STATUS_CLOSED)
            ->exists();
    }

    public function cerrar(string $periodType, string $periodKey, array $meta = []): MlmPeriod
    {
        return MlmPeriod::query()->updateOrCreate(
            [
                'period_type' => $periodType,
                'period_key' => $periodKey,
            ],
            [
                'status' => MlmPeriod::STATUS_CLOSED,
                'closed_at' => now(),
                'meta' => $meta,
            ]
        );
    }

    public function abrir(string $periodType, string $periodKey): MlmPeriod
    {
        return MlmPeriod::query()->updateOrCreate(
            [
                'period_type' => $periodType,
                'period_key' => $periodKey,
            ],
            [
                'status' => MlmPeriod::STATUS_OPEN,
                'closed_at' => null,
                'meta' => null,
            ]
        );
    }
}
