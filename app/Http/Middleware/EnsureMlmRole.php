<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMlmRole
{
    /**
     * Sin parámetro: usa `config('mlm.admin_roles')`.
     * Con parámetro: lista explícita (ej. middleware `mlm.role:admin,support`).
     */
    public function handle(Request $request, Closure $next, string $rolesCsv = ''): Response
    {
        $allowed = $rolesCsv !== ''
            ? array_filter(array_map('trim', explode(',', $rolesCsv)))
            : config('mlm.admin_roles', ['admin']);

        $user = $request->user();

        if (! $user || ! in_array($user->mlm_role ?? '', $allowed, true)) {
            abort(403, 'No autorizado.');
        }

        return $next($request);
    }
}
