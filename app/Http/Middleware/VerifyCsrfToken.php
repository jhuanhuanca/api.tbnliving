<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * Login/registro emiten token Bearer; el resto de mutaciones SPA sigue con CSRF vía /sanctum/csrf-cookie.
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
}
