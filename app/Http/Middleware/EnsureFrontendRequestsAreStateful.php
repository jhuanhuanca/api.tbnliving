<?php

namespace App\Http\Middleware;

use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful as SanctumMiddleware;

/**
 * Sanctum SPA: no forzar SameSite=lax en producción con dominio compartido (.tbnliving.com).
 */
class EnsureFrontendRequestsAreStateful extends SanctumMiddleware
{
    protected function configureSecureCookieSessions(): void
    {
        config(['session.http_only' => true]);

        if (config('app.env') === 'production' && config('session.domain')) {
            return;
        }

        config(['session.same_site' => 'lax']);
    }
}
