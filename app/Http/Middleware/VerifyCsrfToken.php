<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * Rutas públicas sin Bearer (login/registro). El resto autenticado usa Authorization: Bearer.
     *
     * @var array<int, string>
     */
    protected $except = [
        'api/v1/auth/login',
        'api/v1/auth/logout',
        'api/v1/admin/auth/login',
        'api/v1/admin/auth/logout',
        'api/login',
        'api/register',
        'api/register/preferred-customer',
        'api/forgot-password',
        'api/verify-code',
        'api/reset-password',
        'api/email/resend-verification',
    ];

    /**
     * CSRF protege sesión por cookie. Con token Sanctum en Authorization el navegador no envía
     * el token automáticamente (no hay riesgo CSRF clásico).
     */
    protected function tokensMatch($request): bool
    {
        if ($request->bearerToken()) {
            return true;
        }

        return parent::tokensMatch($request);
    }
}
