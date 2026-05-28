<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class TwoFactorController extends Controller
{
    public function status(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'enabled' => (bool) $user->two_factor_enabled,
            'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
        ]);
    }

    /**
     * Genera secreto + QR (NO activa 2FA).
     * Retorna códigos de respaldo en claro una sola vez (se guardan hasheados).
     */
    public function setup(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        // Backup codes: 10 códigos de 8 chars (AAAA-BBBB)
        $backupPlain = [];
        $backupHashed = [];
        for ($i = 0; $i < 10; $i++) {
            $raw = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $code = substr($raw, 0, 4).'-'.substr($raw, 4);
            $backupPlain[] = $code;
            $backupHashed[] = hash('sha256', $code);
        }

        // Guardar secreto cifrado (no activar todavía)
        $user->forceFill([
            'google2fa_secret' => Crypt::encryptString($secret),
            'two_factor_enabled' => false,
            'two_factor_confirmed_at' => null,
            'two_factor_backup_codes' => $backupHashed,
        ])->save();

        $appName = (string) config('app.name', 'TBN');
        $otpauth = $google2fa->getQRCodeUrl($appName, (string) $user->email, $secret);

        $svg = QrCode::format('svg')
            ->size(200)
            ->margin(1)
            ->generate($otpauth);

        return response()->json([
            'ok' => true,
            'secret' => $secret,
            'qr_svg' => $svg,
            'backup_codes' => $backupPlain,
        ]);
    }

    public function confirm(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:16'],
        ]);

        /** @var User $user */
        $user = $request->user();
        if (! $user->google2fa_secret) {
            throw ValidationException::withMessages([
                'twofa' => ['Primero genera el QR para configurar 2FA.'],
            ]);
        }

        try {
            $secret = Crypt::decryptString((string) $user->google2fa_secret);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'twofa' => ['No se pudo leer el secreto 2FA. Genera el QR nuevamente.'],
            ]);
        }

        $google2fa = new Google2FA();
        $code = preg_replace('/\s+/', '', (string) $validated['code']);
        $ok = $google2fa->verifyKey($secret, $code, 2);
        if (! $ok) {
            throw ValidationException::withMessages([
                'code' => ['Código inválido. Verifica tu app autenticadora.'],
            ]);
        }

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ])->save();

        return response()->json([
            'ok' => true,
            'enabled' => true,
        ]);
    }

    public function disable(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:16'],
        ]);

        /** @var User $user */
        $user = $request->user();

        // Permitir desactivar con TOTP válido o con un backup code válido.
        $code = strtoupper(trim((string) $validated['code']));
        $codeNoSpace = preg_replace('/\s+/', '', $code);

        $secret = null;
        if ($user->google2fa_secret) {
            try {
                $secret = Crypt::decryptString((string) $user->google2fa_secret);
            } catch (\Throwable $e) {
                $secret = null;
            }
        }

        $totpOk = false;
        if ($secret) {
            $google2fa = new Google2FA();
            $totpOk = $google2fa->verifyKey($secret, $codeNoSpace, 2);
        }

        $backup = is_array($user->two_factor_backup_codes) ? $user->two_factor_backup_codes : [];
        $hash = hash('sha256', $code);
        $backupOk = in_array($hash, $backup, true);

        if (! $totpOk && ! $backupOk) {
            throw ValidationException::withMessages([
                'code' => ['Código inválido. Usa el TOTP o un código de respaldo.'],
            ]);
        }

        // Si fue backup code, consumirlo.
        if ($backupOk) {
            $user->two_factor_backup_codes = array_values(array_filter($backup, fn ($h) => $h !== $hash));
        }

        $user->forceFill([
            'two_factor_enabled' => false,
            'two_factor_confirmed_at' => null,
            'google2fa_secret' => null,
        ])->save();

        return response()->json(['ok' => true, 'enabled' => false]);
    }
}

