<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreMemberRegisterRequest;
use App\Models\Country;
use App\Models\Package;
use App\Models\Rank;
use App\Models\User;
use App\Events\Internal\UserRegistered;
use App\Services\MemberCodeService;
use App\Services\Registration\MemberRegistrationService;
use Illuminate\Http\Request;

class AuthRegisterController extends Controller
{
    public function __construct(
        private readonly MemberRegistrationService $registration,
    ) {}

    public function register(StoreMemberRegisterRequest $request)
    {
        $validated = $request->validated();

        $availability = $this->registration->analyzeAvailability(
            $validated['email'],
            $validated['document_id'],
        );

        if ($availability['errors'] !== []) {
            $message = StoreMemberRegisterRequest::formatErrorMessage($availability['errors']);

            return response()->json([
                'success' => false,
                'message' => $message,
                'errors' => $availability['errors'],
                'data' => ['errors' => $availability['errors']],
            ], 422);
        }

        if ($availability['reclaim'] instanceof User) {
            $this->registration->reclaimAndDelete($availability['reclaim']);
        }

        $sponsorId = null;
        if (! empty($validated['sponsor_referral_code'])) {
            $sponsor = MemberCodeService::findUserBySponsorCode($validated['sponsor_referral_code']);
            if (! $sponsor) {
                $errors = ['sponsor_referral_code' => ['Código de patrocinador no encontrado.']];

                return response()->json([
                    'success' => false,
                    'message' => 'El código de patrocinador no es válido.',
                    'errors' => $errors,
                    'data' => ['errors' => $errors],
                ], 422);
            }
            $sponsorId = $sponsor->id;
        }

        $packageId = null;
        if (! empty($validated['registration_package_id'])) {
            $exists = Package::query()
                ->where('id', $validated['registration_package_id'])
                ->where('estado', 'activo')
                ->exists();
            if (! $exists) {
                $errors = ['registration_package_id' => ['Paquete no disponible o inactivo.']];

                return response()->json([
                    'success' => false,
                    'message' => 'El paquete de inscripción no es válido.',
                    'errors' => $errors,
                    'data' => ['errors' => $errors],
                ], 422);
            }
            $packageId = (int) $validated['registration_package_id'];
        }

        if (empty($validated['country_id']) && empty($validated['country_code'])) {
            $errors = ['country_code' => ['Debes seleccionar tu país.']];

            return response()->json([
                'success' => false,
                'message' => 'Debes indicar tu país.',
                'errors' => $errors,
                'data' => ['errors' => $errors],
            ], 422);
        }

        $country = null;
        if (! empty($validated['country_id'])) {
            /** @var Country|null $country */
            $country = Country::query()->whereKey((int) $validated['country_id'])->first();
            if ($country === null || $country->code === null || $country->code === '') {
                $errors = ['country_id' => ['País inválido o sin código ISO en el servidor.']];

                return response()->json([
                    'success' => false,
                    'message' => 'El país seleccionado no está disponible en el sistema.',
                    'errors' => $errors,
                    'data' => ['errors' => $errors],
                ], 422);
            }
            if (
                ! empty($validated['country_code'])
                && strtoupper((string) $validated['country_code']) !== strtoupper((string) $country->code)
            ) {
                $errors = ['country_code' => ['El país no coincide con el seleccionado en la lista.']];

                return response()->json([
                    'success' => false,
                    'message' => 'El país enviado no coincide con country_id.',
                    'errors' => $errors,
                    'data' => ['errors' => $errors],
                ], 422);
            }
        } else {
            $code = strtoupper((string) $validated['country_code']);
            $country = Country::query()->where('code', $code)->first();
            if (! $country) {
                $errors = ['country_code' => ['País no registrado en el servidor. Contacta soporte o elige otro país.']];

                return response()->json([
                    'success' => false,
                    'message' => 'País no reconocido. Ejecuta el seeder de países en el API.',
                    'errors' => $errors,
                    'data' => ['errors' => $errors],
                ], 422);
            }
        }

        $rankId = Rank::query()->where('slug', 'activo')->value('id');
        if (! $rankId) {
            return response()->json([
                'success' => false,
                'message' => 'El servidor no tiene configurado el rango inicial (activo). Contacta soporte.',
                'errors' => ['rank' => ['Rango MLM no configurado.']],
                'data' => ['errors' => ['rank' => ['Rango MLM no configurado.']]],
            ], 503);
        }

        try {
            $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'document_id' => $validated['document_id'],
            'phone' => $validated['phone'],
            'birth_date' => $validated['birth_date'],
            'sponsor_id' => $sponsorId,
            'preferred_binary_leg' => $validated['preferred_binary_leg'] ?? null,
            'account_type' => 'member',
            'rank_id' => $rankId,
            // Regla: hasta pagar el paquete de activación, el socio queda pendiente.
            'account_status' => 'pending',
            'country_id' => $country->id,
            'country_code' => strtoupper((string) $country->code),
            'registration_package_id' => $packageId,
            'preferred_payment_method' => $validated['preferred_payment_method'] ?? null,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo crear la cuenta. Verifica los datos o contacta soporte.',
                'errors' => ['general' => [config('app.debug') ? $e->getMessage() : 'Error al guardar el usuario.']],
                'data' => ['errors' => ['general' => ['Error al guardar el usuario.']]],
            ], 500);
        }

        $user->loadMissing('rank', 'sponsor', 'registrationPackage');

        UserRegistered::dispatch($user->fresh());

        $user->sendEmailVerificationNotification();

        return response()->json([
            'success' => true,
            'message' => 'Cuenta creada. Revisa tu correo y confirma el enlace antes de iniciar sesión.',
            'requires_email_verification' => true,
            'email' => $user->email,
        ], 201);
    }
}
