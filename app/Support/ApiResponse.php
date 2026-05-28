<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    public static function success(mixed $data = null, string $message = '', int $status = 200, array $meta = []): JsonResponse
    {
        $payload = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    public static function error(string $message, int $status = 400, mixed $data = null, ?string $code = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
            'data' => $data,
        ];

        if ($code !== null) {
            $payload['code'] = $code;
        }

        return response()->json($payload, $status);
    }

    /** Respuesta compatible con panel admin (campos planos + envoltorio estándar). */
    public static function panelAuthLogin(string $accessToken, array $admin, string $message = 'Sesión iniciada'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'access_token' => $accessToken,
            'admin' => $admin,
            'data' => [
                'access_token' => $accessToken,
                'admin' => $admin,
            ],
        ]);
    }

    public static function panelAuthMe(array $admin): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => '',
            'admin' => $admin,
            'data' => ['admin' => $admin],
        ]);
    }

    /** Login miembros: envoltorio estándar + campos planos para el front legacy. */
    public static function memberAuthLogin(string $token, array $user, string $message = 'Sesión iniciada'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'token' => $token,
            'user' => $user,
            'data' => [
                'token' => $token,
                'user' => $user,
            ],
        ]);
    }

    public static function memberAuthMe(array $user): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => '',
            'user' => $user,
            'data' => ['user' => $user],
        ]);
    }
}
