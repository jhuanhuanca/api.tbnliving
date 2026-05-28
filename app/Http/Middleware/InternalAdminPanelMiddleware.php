<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marca la petición como proxy de panel admin (autorización Policies vía Gate::before en AppServiceProvider).
 * Debe ir después de {@see InternalApiMiddleware} en la cadena.
 */
class InternalAdminPanelMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('internal_admin_proxy', true);

        return $next($request);
    }
}
