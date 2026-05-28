<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InternalApiMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Read-only enforcement: solo GET/HEAD
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return response()->json(['message' => 'Method not allowed.'], 405);
        }

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

