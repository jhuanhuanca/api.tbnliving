<?php

namespace App\Providers;

use App\Contracts\ElectronicInvoiceGatewayInterface;
use App\Events\OrderCompleted;
use App\Events\UserActivated;
use App\Events\Internal\AffiliateActivated;
use App\Events\WithdrawalCreated;
use App\Events\WithdrawalStatusChanged;
use App\Listeners\InvalidateMlmCache;
use App\Listeners\SendWithdrawalNotificationMail;
use App\Listeners\QueueMlmProcessingOnOrderCompleted;
use App\Models\BinaryPlacement;
use App\Models\Withdrawal;
use App\Policies\BinaryPlacementPolicy;
use App\Policies\WithdrawalPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ElectronicInvoiceGatewayInterface::class, function ($app) {
            if (config('mlm.invoice.electronic.enabled')) {
                return $app->make(\App\Services\Invoicing\HttpElectronicInvoiceGateway::class);
            }

            return $app->make(\App\Services\Invoicing\NullElectronicInvoiceGateway::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Producción multi-subdominio (admin/front → api): cookies compartidas.
        if (config('session.domain') && config('app.env') === 'production') {
            config([
                'session.secure' => filter_var(
                    env('SESSION_SECURE_COOKIE', true),
                    FILTER_VALIDATE_BOOL,
                ),
                'session.same_site' => env('SESSION_SAME_SITE', 'none'),
            ]);
        }

        Gate::policy(Withdrawal::class, WithdrawalPolicy::class);
        Gate::policy(BinaryPlacement::class, BinaryPlacementPolicy::class);

        /** Rutas internas autenticadas por X-Internal-Token (p. ej. panel admin remoto). */
        Gate::before(function ($user, string $ability, array $arguments = []) {
            if (request()->attributes->get('internal_admin_proxy')) {
                return true;
            }

            return null;
        });

        Event::listen(OrderCompleted::class, QueueMlmProcessingOnOrderCompleted::class);
        Event::listen(OrderCompleted::class, [InvalidateMlmCache::class, 'handleOrderCompleted']);
        Event::listen(UserActivated::class, function (UserActivated $e) {
            // Bridge a evento interno estable para pipelines/event-driven del panel
            AffiliateActivated::dispatch($e->user, $e->qualificationMonth);
        });

        if (class_exists(WithdrawalCreated::class) && class_exists(SendWithdrawalNotificationMail::class)) {
            $withdrawalMail = SendWithdrawalNotificationMail::class;
            Event::listen(WithdrawalCreated::class, [$withdrawalMail, 'handleCreated']);
            if (class_exists(WithdrawalStatusChanged::class)) {
                Event::listen(WithdrawalStatusChanged::class, [$withdrawalMail, 'handleStatusChanged']);
                Event::listen(WithdrawalStatusChanged::class, [InvalidateMlmCache::class, 'handleWithdrawalStatusChanged']);
            }
        }

        RateLimiter::for('withdraw-otp-request', function (Request $request) {
            $uid = $request->user()?->id ?? $request->ip();

            return [
                Limit::perMinute(5)->by('wd-req|'.$request->ip()),
                Limit::perMinute(3)->by('wd-req|user|'.$uid),
            ];
        });

        RateLimiter::for('withdraw-otp-verify', function (Request $request) {
            $uid = $request->user()?->id ?? $request->ip();

            return [
                Limit::perMinute(15)->by('wd-ver|'.$request->ip()),
                Limit::perMinute(10)->by('wd-ver|user|'.$uid),
            ];
        });

        RateLimiter::for('withdraw-otp-resend', function (Request $request) {
            $uid = $request->user()?->id ?? $request->ip();

            return [
                Limit::perMinute(5)->by('wd-res|'.$request->ip()),
                Limit::perMinute(3)->by('wd-res|user|'.$uid),
            ];
        });

        RateLimiter::for('internal-sync', function (Request $request) {
            $perMin = (int) config('internal_sync.rate_limit_per_minute', 240);
            $key = 'internal-sync|'.$request->ip();
            return Limit::perMinute(max(30, $perMin))->by($key);
        });

        RateLimiter::for('password-reset-send', function (Request $request) {
            $email = strtolower((string) $request->input('email', ''));

            return [
                Limit::perMinute(5)->by($request->ip()),
                Limit::perMinute(3)->by('email|'.$email),
            ];
        });

        RateLimiter::for('password-reset-verify', function (Request $request) {
            $email = strtolower((string) $request->input('email', ''));

            return [
                Limit::perMinute(15)->by($request->ip()),
                Limit::perMinute(10)->by('verify|'.$email),
            ];
        });

        RateLimiter::for('password-reset-reset', function (Request $request) {
            $email = strtolower((string) $request->input('email', ''));

            return [
                Limit::perMinute(10)->by($request->ip()),
                Limit::perMinute(5)->by('reset|'.$email),
            ];
        });
    }
}
