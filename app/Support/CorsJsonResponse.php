<?php

namespace App\Support;

use Fruitcake\Cors\CorsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CorsJsonResponse
{
    public static function make(Request $request, array $data, int $status = 200): JsonResponse
    {
        $response = response()->json($data, $status);

        return app(CorsService::class)->addActualRequestHeaders($response, $request);
    }

    public static function shouldApply(Request $request): bool
    {
        return $request->is('api/*')
            || $request->expectsJson()
            || $request->header('Origin') !== null
            || $request->header('X-Requested-With') === 'XMLHttpRequest';
    }
}
