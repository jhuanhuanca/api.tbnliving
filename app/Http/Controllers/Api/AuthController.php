<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Services\Auth\MemberTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function __construct(
        private readonly MemberTokenService $tokens,
    ) {}

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'country_code' => ['required', 'string', 'size:2'],
        ]);

        if (! Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'Credenciales incorrectas',
            ], 401);
        }

        /** @var \App\Models\User $user */
        $user = $request->user();
        $user->loadMissing('country');

        if (! $user->hasVerifiedEmail()) {
            Auth::logout();

            return response()->json([
                'message' => 'Debes confirmar tu correo electrónico. Revisa la bandeja de entrada o solicita un nuevo enlace.',
                'code' => 'email_unverified',
                'email' => $user->email,
            ], 403);
        }

        // Panel admin: omitir vínculo de país con el cliente.
        $sent = strtoupper((string) $request->country_code);

        if (! $user->canAccessAdminPanel()) {
            /** @var Country|null $canonical */
            $canonical = Country::query()->where('code', $sent)->first();
            if (! $canonical && ! $user->country_id && ! $user->country_code) {
                Auth::logout();

                return response()->json([
                    'message' => 'País no reconocido. Contacta a soporte o completa tus datos tras actualizar catálogo de países.',
                    'code' => 'invalid_country_login',
                ], 422);
            }

            // Primer login de cuentas legadas sin país — asociar país si existe en tabla.
            if (! $user->country_id && ($user->country_code === null || $user->country_code === '')) {
                if ($canonical !== null) {
                    $user->forceFill([
                        'country_id' => $canonical->id,
                        'country_code' => strtoupper((string) $canonical->code),
                    ])->save();
                    $user->refresh();
                } elseif ($sent !== '') {
                    Auth::logout();

                    return response()->json([
                        'message' => 'Tu cuenta no tiene país configurado en el servidor. Solicita soporte.',
                        'code' => 'country_required',
                    ], 422);
                }
            }

            $registered = strtoupper((string) (($user->country?->code) ?? ($user->country_code ?? '')));
            if ($registered !== '' && $registered !== $sent) {
                Auth::logout();

                return response()->json([
                    'message' => 'El país seleccionado no coincide con tu registro.',
                    'code' => 'country_mismatch',
                ], 403);
            }
        }

        $token = $this->tokens->issue($user, $request, 'member-legacy');

        $user->loadMissing('rank', 'sponsor', 'registrationPackage', 'country');
        $payload = array_merge($user->toArray(), [
            'country' => $user->country?->toApiArray(),
        ]);

        return response()->json([
            'token' => $token,
            'user' => $payload,
        ]);
    }
}
