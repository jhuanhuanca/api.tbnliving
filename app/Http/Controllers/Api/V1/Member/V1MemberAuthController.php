<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Services\Member\UserProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class V1MemberAuthController extends Controller
{
    public function login(Request $request, AuthController $legacy): JsonResponse
    {
        $response = $legacy->login($request);
        $status = $response->getStatusCode();
        $body = $response->getData(true);

        if ($status !== 200) {
            $extra = null;
            if (isset($body['email'])) {
                $extra = ['email' => $body['email']];
            }

            return ApiResponse::error(
                (string) ($body['message'] ?? 'Error de autenticación'),
                $status,
                $extra,
                $body['code'] ?? null,
            );
        }

        return ApiResponse::memberAuthLogin(
            (string) ($body['token'] ?? ''),
            (array) ($body['user'] ?? []),
        );
    }

    public function me(Request $request, UserProfileService $userProfileService): JsonResponse
    {
        $user = $userProfileService->buildFromRequest($request);

        return ApiResponse::memberAuthMe($user);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token) {
            $token->delete();
        }

        return ApiResponse::success(null, 'Sesión cerrada correctamente');
    }
}
