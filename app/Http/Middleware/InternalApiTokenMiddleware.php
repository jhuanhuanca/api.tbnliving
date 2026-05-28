<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Valida X-Internal-Token (panel admin / sync). Permite GET y POST (acciones admin internas).
 */
class InternalApiTokenMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('internal_sync.token', '');
        $provided = (string) $request->header('X-Internal-Token', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $allow = (array) config('internal_sync.ip_allowlist', []);
        if ($allow !== []) {
            $ip = (string) $request->ip();
            if ($ip === '' || ! in_array($ip, $allow, true)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        return $next($request);
    }
}
