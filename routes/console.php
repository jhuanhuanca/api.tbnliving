<?php

use App\Jobs\CalculateBinaryCommissionsJob;
use App\Jobs\PayDeferredCommissionsWeeklyJob;
use App\Jobs\ProcessResidualCommissionsJob;
use App\Models\BinaryLegVolumeWeekly;
use App\Models\Order;
use App\Models\PeriodClosure;
use App\Models\User;
use App\Services\PeriodService;
use App\Services\RankService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('mlm:close-binary {periodKey?}', function (?string $periodKey) {
    $key = $periodKey;
    if ($key === null || $key === '') {
        $key = config('mlm.binary.volume_period', 'monthly') === 'weekly'
            ? now()->subWeek()->format('o-\WW')
            : now()->subMonth()->format('Y-m');
    }
    CalculateBinaryCommissionsJob::dispatchSync($key);
    $this->info("Cierre binario ejecutado (sync) para {$key}");
})->purpose('Cierre binario (pierna débil 21 % + carry): mes Y-m por defecto, o semana ISO si MLM_BINARY_VOLUME_PERIOD=weekly');

Artisan::command('mlm:close-weekly-binary {weekKey?}', function (?string $weekKey) {
    $key = $weekKey ?? now()->subWeek()->format('o-\WW');
    CalculateBinaryCommissionsJob::dispatchSync($key);
    $this->info("Cierre binario (semana ISO) ejecutado para {$key}");
})->purpose('Alias retrocompatible: cierre binario semanal ISO');

Artisan::command('mlm:close-monthly-residual {monthKey?}', function (?string $monthKey) {
    $key = $monthKey ?? now()->subMonth()->format('Y-m');
    ProcessResidualCommissionsJob::dispatchSync($key);
    $this->info("Cierre mensual (auditoría residual) {$key}");
})->purpose('Cierre mensual / auditoría residual');

Artisan::command('mlm:pay-deferred-commissions {weekKey?}', function (?string $weekKey) {
    $key = $weekKey ?? now()->subWeek()->format('o-\WW');
    PayDeferredCommissionsWeeklyJob::dispatchSync($key);
    $this->info("Pago comisiones diferidas (BIR/residual/venta directa/liderazgo) para semana ISO {$key}");
})->purpose('Abona en billetera comisiones diferidas de la semana ISO (cierre semanal)');

Artisan::command('mlm:accounting-period {action} {type} {periodKey}', function (string $action, string $type, string $periodKey) {
    $period = app(PeriodService::class);
    if ($action === 'close') {
        $period->cerrar($type, $periodKey, ['by' => 'cli']);
        $this->info("Periodo {$type} {$periodKey} cerrado.");
    } elseif ($action === 'open') {
        $period->abrir($type, $periodKey);
        $this->info("Periodo {$type} {$periodKey} abierto.");
    } else {
        $this->error('Acción: close | open');

        return 1;
    }

    return 0;
})->purpose('Cierra/abre periodos contables MLM (bloquea reproceso de comisiones)');

Artisan::command('mlm:reevaluate-ranks', function () {
    $n = app(RankService::class)->reevaluarTodosLosRangos();
    $this->info("Usuarios con cambio de rango: {$n}");
})->purpose('Re-evalúa rangos por PV mensual (batch)');

/**
 * Backfill (una sola vez) para llenar nuevos PV acumulados con datos ya existentes.
 *
 * Campos:
 * - lifetime_purchase_pv: PV acumulado por compras/paquetes (para el modal de paquetes).
 * - lifetime_qualifying_pv: PV histórico para rangos/carrera (desde volumen binario).
 *
 * Nota:
 * - Requiere que las migraciones estén aplicadas (campos existan).
 * - Recomendada ejecución en ventana de mantenimiento.
 *
 * Uso:
 * - php artisan mlm:backfill-pv --dry
 * - php artisan mlm:backfill-pv
 */
Artisan::command('mlm:backfill-pv {--dry}', function () {
    $dry = (bool) $this->option('dry');

    $this->info('Backfill PV: iniciando...');

    // 1) lifetime_purchase_pv: suma PV comisionable de pedidos completados por usuario.
    // Usamos el total_pv del pedido si existe; si no, recalculamos por líneas.
    $this->info('1/3 Calculando lifetime_purchase_pv desde pedidos completados...');
    $byUser = Order::query()
        ->where('estado', 'completado')
        ->selectRaw('user_id, SUM(COALESCE(total_pv, 0)) as pv_sum')
        ->groupBy('user_id')
        ->get();

    if (! $dry) {
        DB::transaction(function () use ($byUser) {
            foreach ($byUser as $row) {
                $uid = (int) ($row->user_id ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                $pv = (string) ($row->pv_sum ?? '0');
                if (! is_numeric($pv)) {
                    $pv = '0';
                }
                DB::statement(
                    'UPDATE users SET lifetime_purchase_pv = ? WHERE id = ?',
                    [bcadd($pv, '0', 2), $uid]
                );
            }
        });
    }

    $this->info('2/3 Calculando lifetime_qualifying_pv desde volumen binario histórico...');
    // 2) lifetime_qualifying_pv: suma de volumen binario raw de TODOS los periodos (izq+der ya está en la suma).
    // binary_leg_volume_weekly tiene una fila por pierna, por periodo. Sumamos todo por parent_user_id.
    $vol = BinaryLegVolumeWeekly::query()
        ->selectRaw('parent_user_id, SUM(volume_pv) as gv_sum')
        ->groupBy('parent_user_id')
        ->get();

    if (! $dry) {
        DB::transaction(function () use ($vol) {
            foreach ($vol as $row) {
                $uid = (int) ($row->parent_user_id ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                $pv = (string) ($row->gv_sum ?? '0');
                if (! is_numeric($pv)) {
                    $pv = '0';
                }
                DB::statement(
                    'UPDATE users SET lifetime_qualifying_pv = ? WHERE id = ?',
                    [bcadd($pv, '0', 2), $uid]
                );
            }
        });
    }

    $this->info('3/3 Marcando cierres binarios históricos como aplicados (evita doble acumulación)...');
    // 3) Evitar doble conteo: si vuelves a correr cierre binario de un periodo viejo,
    // nuestra lógica nueva aplicaría career PV si PeriodClosure.meta no está marcado.
    // Marcamos todos los cierres finished del scope=binary.
    if (! $dry) {
        PeriodClosure::query()
            ->where('scope', 'binary')
            ->where('status', 'finished')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $c) {
                    $meta = (array) ($c->meta ?? []);
                    if (($meta['career_pv_applied'] ?? false) === true) {
                        continue;
                    }
                    $meta['career_pv_applied'] = true;
                    $meta['career_pv_applied_at'] = now()->toIso8601String();
                    $c->update(['meta' => $meta]);
                }
            });
    }

    $this->info($dry ? 'Backfill PV (dry-run) finalizado.' : 'Backfill PV finalizado.');
});
