<?php

namespace App\Support;

use App\Models\User;

/** Datos de cobro guardados en users.meta.wallet_settings (CardCuenta / AccountController). */
final class WalletSettingsPresenter
{
    /**
     * @return array<string, string|null>|null
     */
    public static function fromUser(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        $meta = is_array($user->meta) ? $user->meta : [];
        $raw = $meta['wallet_settings'] ?? null;
        if (! is_array($raw)) {
            return null;
        }

        $normalized = [
            'method' => self::strOrNull($raw['method'] ?? null),
            'currency' => self::strOrNull($raw['currency'] ?? null),
            'address' => self::strOrNull($raw['address'] ?? null),
            'bank' => self::strOrNull($raw['bank'] ?? null),
            'holder' => self::strOrNull($raw['holder'] ?? null),
            'account' => self::strOrNull($raw['account'] ?? null),
            'swift' => self::strOrNull($raw['swift'] ?? null),
        ];

        $hasData = false;
        foreach ($normalized as $v) {
            if ($v !== null && $v !== '') {
                $hasData = true;
                break;
            }
        }

        return $hasData ? $normalized : null;
    }

    public static function attachToUser(User $user): void
    {
        $user->setAttribute('wallet_settings', self::fromUser($user));
        $user->makeHidden('meta');
    }

    private static function strOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }
}
