<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminPanelAuthService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminV1AuthController extends Controller
{
    public function __construct(
        private readonly AdminPanelAuthService $panelAuth,
    ) {}

    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'country_code' => ['nullable', 'string', 'size:2'],
        ]);

        $country = strtoupper((string) ($request->input('country_code')
            ?: config('admin_panel.default_country_code', 'BO')));

        if (! Auth::guard('web')->attempt(
            $request->only('email', 'password'),
            $request->boolean('remember'),
        )) {
            return ApiResponse::error('Credenciales incorrectas', 401, null, 'invalid_credentials');
        }

        $request->session()->regenerate();

        /** @var \App\Models\User $user */
        $user = Auth::guard('web')->user();

        if (! $user->hasVerifiedEmail()) {
            Auth::guard('web')->logout();

            return ApiResponse::error(
                'Debes confirmar tu correo electrónico antes de acceder al panel.',
                403,
                ['email' => $user->email],
                'email_unverified',
            );
        }

        $this->panelAuth->assertCanAccessPanel($user);

        $device = (string) ($request->input('device_name') ?: 'panel-admin');
        $token = $user->createToken($device)->plainTextToken;

        return ApiResponse::panelAuthLogin($token, $this->panelAuth->formatAdminUser($user));
    }

    public function me(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $this->panelAuth->assertCanAccessPanel($user);

        return ApiResponse::panelAuthMe($this->panelAuth->formatAdminUser($user));
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user && method_exists($user, 'currentAccessToken')) {
            $token = $user->currentAccessToken();
            if ($token) {
                $token->delete();
            }
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return ApiResponse::success(null, 'Sesión cerrada correctamente');
    }
}
