<?php

use App\Jobs\CalculateBinaryCommissionsJob;
use App\Jobs\CalculateBinaryDailyBonusesJob;
use App\Jobs\CalculateBinaryWeeklyHybridPayoutJob;
use App\Jobs\ProcessResidualCommissionsJob;
use App\Services\RankService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use App\Http\Middleware\EnsureMlmRole;
use App\Http\Middleware\InternalAdminPanelMiddleware;
use App\Http\Middleware\InternalApiMiddleware;
use App\Http\Middleware\InternalApiTokenMiddleware;
use Illuminate\Foundation\Configuration\Middleware;
use App\Jobs\ApplyBinaryMonthlyPenaltyJob;
use Fruitcake\Cors\CorsService;
use App\Jobs\CalculateLeadershipMonthlyBonusesJob;
use App\Jobs\PayDeferredCommissionsWeeklyJob;
use App\Models\User;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
)
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');
        $middleware->statefulApi();
        $middleware->encryptCookies(except: [
            'XSRF-TOKEN',
        ]);
        $middleware->validateCsrfTokens(except: [
            // Registro/login SPA: CSRF vía /sanctum/csrf-cookie (no excluir rutas).
        ]);
        $middleware->alias([
            'mlm.admin' => EnsureMlmRole::class,
            'internal.api' => InternalApiMiddleware::class,
            'internal.token' => InternalApiTokenMiddleware::class,
            'internal.admin.panel' => InternalAdminPanelMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof \Illuminate\Validation\ValidationException) {
                $errors = $e->errors();
                $first = collect($errors)->flatten()->first() ?: 'Datos inválidos';

                return response()->json([
                    'success' => false,
                    'message' => $first,
                    'errors' => $errors,
                    'data' => ['errors' => $errors],
                ], 422);
            }

            $status = 500;
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                $status = $e->getStatusCode();
            }

            $message = $e->getMessage() ?: 'Error en el servidor';
            if ($status >= 500 && ! config('app.debug')) {
                $message = 'Error interno del servidor';
            }

            $response = response()->json([
                'success' => false,
                'message' => $message,
                'data' => config('app.debug') ? [
                    'exception' => class_basename($e),
                ] : null,
            ], $status);

            return app(CorsService::class)->addActualRequestHeaders($response, $request);
        });
    })
    ->withSchedule(function (Schedule $schedule) {
        // Binario híbrido diario (B): recalcular cada hora (día actual)
        if (config('mlm.binary.hybrid_daily.enabled', false)) {
            $schedule->call(function () {
                // Recalcula el día actual para reflejar PV que entra durante el día.
                $dayKey = now()->toDateString();
                CalculateBinaryDailyBonusesJob::dispatch($dayKey);
            })->hourlyAt(10);
        }

        // Cierre binario:
        // - Híbrido diario (B): siempre cierra semanal.
        // - Legacy: cierra semanal o mensual según binary.volume_period.
        $schedule->call(function () {
            $weekKey = now()->subWeek()->format('o-\WW');
            PayDeferredCommissionsWeeklyJob::dispatch($weekKey);
        })->weekly()->sundays()->at('03:05');

        if (config('mlm.binary.hybrid_daily.enabled', false)) {
            $schedule->call(function () {
                $weekKey = now()->subWeek()->format('o-\WW');
                CalculateBinaryWeeklyHybridPayoutJob::dispatch($weekKey);
            })->weekly()->mondays()->at('00:20');
        } elseif (config('mlm.binary.volume_period', 'monthly') === 'weekly') {
            $schedule->call(function () {
                $weekKey = now()->subWeek()->format('o-\WW');
                CalculateBinaryCommissionsJob::dispatch($weekKey);
            })->weekly()->sundays()->at('03:00');
        } else {
            $schedule->call(function () {
                $monthKey = now()->subMonth()->format('Y-m');
                CalculateBinaryCommissionsJob::dispatch($monthKey);
            })->monthlyOn(1, '03:00');
        }

        $schedule->call(function () {
            $monthKey = now()->subMonth()->format('Y-m');
            ProcessResidualCommissionsJob::dispatch($monthKey);
        })->monthlyOn(1, '04:00');

        // Bono de liderazgo (mensual) para el mes anterior.
        $schedule->call(function () {
            $monthKey = now()->subMonth()->format('Y-m');
            CalculateLeadershipMonthlyBonusesJob::dispatch($monthKey);
        })->monthlyOn(1, '04:10');

        // Reset PV mensual (calificación): arranca mes nuevo en 0.
        // lifetime_qualifying_pv NO se reinicia (base histórica para rangos/carrera).
        $schedule->call(function () {
            User::query()->update([
                'monthly_qualifying_pv' => 0,
                'is_mlm_qualified' => false,
                'last_qualification_month' => now()->format('Y-m'),
            ]);
        })->monthlyOn(1, '00:05');

        // Penalización mensual del acumulado no pagado (binario híbrido).
        if (config('mlm.binary.hybrid_daily.enabled', false)) {
            $schedule->call(function () {
                $monthKey = now()->subMonth()->format('Y-m');
                ApplyBinaryMonthlyPenaltyJob::dispatch($monthKey);
            })->monthlyOn(1, '04:30');
        }

        $schedule->call(function () {
            app(RankService::class)->reevaluarTodosLosRangos();
        })->monthlyOn(2, '05:00');

        $schedule->command('mlm:purge-inactive-members')->monthlyOn(7, '06:00');

        // Limpieza automática: registros sin verificación o sin pago de activación.
        $schedule->command('mlm:prune-stale-registrations')->hourly();
    })
    ->create();
