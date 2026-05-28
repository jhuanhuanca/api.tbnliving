<?php

namespace App\Services\Auth;

use App\Models\PasswordReset;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PasswordResetOtpService
{
    public const OTP_TTL_MINUTES = 10;

    public function generatePlainCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function createForEmail(string $email, string $plainCode): PasswordReset
    {
        $normalized = Str::lower(trim($email));

        PasswordReset::query()->where('email', $normalized)->delete();

        return PasswordReset::query()->create([
            'email' => $normalized,
            'code' => Hash::make($plainCode),
            'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
        ]);
    }

    public function findValid(string $email, string $plainCode): ?PasswordReset
    {
        $normalized = Str::lower(trim($email));
        $code = trim($plainCode);

        if ($code === '') {
            return null;
        }

        $record = PasswordReset::query()
            ->where('email', $normalized)
            ->latest('id')
            ->first();

        if (! $record || $record->isExpired()) {
            return null;
        }

        if (! Hash::check($code, $record->code)) {
            return null;
        }

        return $record;
    }

    public function deleteForEmail(string $email): void
    {
        PasswordReset::query()->where('email', Str::lower(trim($email)))->delete();
    }

    public function findUserByEmail(string $email): ?User
    {
        return User::query()->where('email', Str::lower(trim($email)))->first();
    }
}
