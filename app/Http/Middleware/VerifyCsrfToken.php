<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * Rutas públicas API: usar flujo Sanctum (GET /sanctum/csrf-cookie + X-XSRF-TOKEN).
     * No se excluyen aquí para mantener protección CSRF cross-site.
     *
     * @var array<int, string>
     */
    protected $except = [
        //
    ];
}
