<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Mail\ResetPasswordCodeMail;
use App\Services\Auth\PasswordResetOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;
use Throwable;

class ForgotPasswordController extends Controller
{
    public function __construct(
        protected PasswordResetOtpService $otpService
    ) {}

    /**
     * POST /api/forgot-password
     */
    public function sendCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = strtolower(trim($validated['email']));

        try {
            $user = $this->otpService->findUserByEmail($email);

            if ($user) {
                $plainCode = $this->otpService->generatePlainCode();
                $this->otpService->createForEmail($email, $plainCode);

                Mail::to($email)->send(new ResetPasswordCodeMail(
                    code: $plainCode,
                    userName: (string) $user->name,
                    expiresMinutes: PasswordResetOtpService::OTP_TTL_MINUTES,
                ));
            }
        } catch (Throwable $e) {
            Log::error('password_reset.send_code_failed', [
                'email' => $email,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No pudimos enviar el código. Inténtalo más tarde.',
            ], 503);
        }

        // Respuesta genérica: no revelar si el correo existe.
        return response()->json([
            'success' => true,
            'message' => 'Si el correo está registrado, recibirás un código de 6 dígitos en unos minutos.',
            'expires_in_minutes' => PasswordResetOtpService::OTP_TTL_MINUTES,
        ]);
    }

    /**
     * POST /api/verify-code
     */
    public function verifyCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ]);

        $email = strtolower(trim($validated['email']));
        $code = trim($validated['code']);

        try {
            $record = $this->otpService->findValid($email, $code);

            if (! $record) {
                return response()->json([
                    'success' => false,
                    'message' => 'Código inválido o expirado.',
                ], 422);
            }

            return response()->json([
                'success' => true,
                'verified' => true,
                'message' => 'Código verificado. Ya puedes establecer tu nueva contraseña.',
            ]);
        } catch (Throwable $e) {
            Log::error('password_reset.verify_failed', ['email' => $email, 'message' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo validar el código.',
            ], 500);
        }
    }

    /**
     * POST /api/reset-password
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $email = strtolower(trim($validated['email']));
        $code = trim($validated['code']);

        try {
            $user = $this->otpService->findUserByEmail($email);
            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo restablecer la contraseña.',
                ], 422);
            }

            $record = $this->otpService->findValid($email, $code);
            if (! $record) {
                return response()->json([
                    'success' => false,
                    'message' => 'Código inválido o expirado.',
                ], 422);
            }

            $user->password = $validated['password'];
            $user->save();

            $this->otpService->deleteForEmail($email);

            return response()->json([
                'success' => true,
                'message' => 'Contraseña actualizada correctamente. Ya puedes iniciar sesión.',
            ]);
        } catch (Throwable $e) {
            Log::error('password_reset.reset_failed', ['email' => $email, 'message' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo actualizar la contraseña.',
            ], 500);
        }
    }
}
