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
use App\Support\CorsJsonResponse;
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
        $middleware->replaceInGroup(
            'api',
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \App\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        );
        $middleware->encryptCookies(except: [
            'XSRF-TOKEN',
        ]);
        $middleware->validateCsrfTokens(except: [
            // Públicas sin Bearer. Autenticadas: VerifyCsrfToken omite CSRF si hay Authorization Bearer.
            'api/v1/auth/login',
            'api/v1/auth/logout',
            'api/v1/admin/auth/login',
            'api/v1/admin/auth/logout',
            // Subida multipart: el Bearer debe bastar; esto evita 419 si el header no llega en FormData.
            'api/v1/admin/products',
            'api/v1/admin/products/*',
            'api/v1/admin/events',
            'api/v1/admin/events/*',
            'api/v1/admin/news',
            'api/v1/admin/news/*',
            'api/login',
            'api/register',
            'api/register/preferred-customer',
            'api/forgot-password',
            'api/verify-code',
            'api/reset-password',
            'api/email/resend-verification',
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
            if (! CorsJsonResponse::shouldApply($request)) {
                return null;
            }

            if ($e instanceof \Illuminate\Session\TokenMismatchException) {
                return CorsJsonResponse::make($request, [
                    'success' => false,
                    'message' => 'Token CSRF inválido o sesión expirada. Recarga la página e intenta de nuevo.',
                    'code' => 'csrf_token_mismatch',
                    'data' => null,
                ], 419);
            }

            if ($e instanceof \Illuminate\Http\Exceptions\PostTooLargeException) {
                return CorsJsonResponse::make($request, [
                    'success' => false,
                    'message' => 'El archivo es demasiado grande. Máximo permitido: 5 MB.',
                    'code' => 'payload_too_large',
                    'data' => null,
                ], 413);
            }

            if ($e instanceof \App\Exceptions\InsufficientStockException) {
                return CorsJsonResponse::make($request, [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'code' => 'insufficient_stock',
                    'data' => null,
                ], 422);
            }

            if ($e instanceof \Illuminate\Validation\ValidationException) {
                $errors = $e->errors();
                $first = collect($errors)->flatten()->first() ?: 'Datos inválidos';

                return CorsJsonResponse::make($request, [
                    'success' => false,
                    'message' => $first,
                    'errors' => $errors,
                    'data' => ['errors' => $errors],
                ], 422);
            }

            if ($e instanceof \Illuminate\Database\QueryException) {
                $sqlMessage = $e->getMessage();
                $hint = null;
                if (str_contains($sqlMessage, 'image_path')
                    || str_contains($sqlMessage, 'image_mime')
                    || str_contains($sqlMessage, 'image_original_name')) {
                    $hint = 'Faltan columnas de imagen en productos. Ejecute en el servidor: php artisan migrate --force';
                }

                return CorsJsonResponse::make($request, [
                    'success' => false,
                    'message' => $hint ?? (config('app.debug') ? $sqlMessage : 'Error de base de datos'),
                    'code' => $hint ? 'migration_required' : 'database_error',
                    'data' => config('app.debug') ? ['exception' => class_basename($e)] : null,
                ], $hint ? 503 : 500);
            }

            $status = 500;
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                $status = $e->getStatusCode();
            }

            $message = $e->getMessage() ?: 'Error en el servidor';
            if ($status >= 500 && ! config('app.debug')) {
                $message = 'Error interno del servidor';
            }

            return CorsJsonResponse::make($request, [
                'success' => false,
                'message' => $message,
                'data' => config('app.debug') ? [
                    'exception' => class_basename($e),
                ] : null,
            ], $status);
        });

        // CORS en cualquier respuesta de error (incluye 500 sin pasar por render).
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response, \Throwable $e, \Illuminate\Http\Request $request) {
            if (! CorsJsonResponse::shouldApply($request)) {
                return $response;
            }

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
