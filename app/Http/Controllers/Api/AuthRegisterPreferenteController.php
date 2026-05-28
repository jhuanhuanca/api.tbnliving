<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\Rank;
use App\Models\User;
use App\Services\MemberCodeService;
use Illuminate\Http\Request;

/**
 * Registro de cliente preferente: solo verificación de correo; compra a precio cliente; bono al patrocinador.
 */
class AuthRegisterPreferenteController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'document_id' => ['required', 'string', 'max:64', 'unique:users,document_id'],
            'sponsor_referral_code' => ['required', 'string', 'max:32'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'country_code' => ['nullable', 'string', 'size:2'],
        ]);

        $sponsor = MemberCodeService::findUserBySponsorCode($validated['sponsor_referral_code']);
        if (! $sponsor) {
            return response()->json([
                'message' => 'El código de patrocinador no es válido.',
                'errors' => ['sponsor_referral_code' => ['Patrocinador no encontrado.']],
            ], 422);
        }

        if ($sponsor->isPreferredCustomer()) {
            return response()->json([
                'message' => 'Debes indicar el código de un socio (no otro cliente preferente).',
                'errors' => ['sponsor_referral_code' => ['Patrocinador inválido.']],
            ], 422);
        }

        $countryPayload = [];
        if (! empty($validated['country_id'])) {
            /** @var Country|null $country */
            $country = Country::query()->whereKey((int) $validated['country_id'])->first();
            if ($country && $country->code) {
                $countryPayload['country_id'] = $country->id;
                $countryPayload['country_code'] = strtoupper((string) $country->code);
            }
        } elseif (! empty($validated['country_code'])) {
            /** @var Country|null $country */
            $country = Country::query()->where('code', strtoupper((string) $validated['country_code']))->first();
            if ($country) {
                $countryPayload['country_id'] = $country->id;
                $countryPayload['country_code'] = strtoupper((string) $country->code);
            }
        }

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'document_id' => $validated['document_id'],
            'phone' => null,
            'birth_date' => null,
            'sponsor_id' => $sponsor->id,
            'account_type' => 'preferred_customer',
            'rank_id' => Rank::query()->where('slug', 'sin_rango')->value('id'),
            // Mantener pendiente mientras verifica correo (login ya lo bloquea si no verifica).
            'account_status' => 'pending',
            'mlm_role' => 'member',
            ...$countryPayload,
        ]);

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Cuenta de cliente preferente creada. Confirma tu correo para ingresar.',
            'requires_email_verification' => true,
            'email' => $user->email,
        ], 201);
    }
}
