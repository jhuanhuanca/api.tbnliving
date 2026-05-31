<?php

namespace App\Support;

/**
 * Cookies de sesión compartidas entre api / front / admin (.tbnliving.com).
 */
final class SpaSessionConfig
{
    public static function apply(): void
    {
        if (! config('session.domain')) {
            $host = parse_url((string) config('app.url', ''), PHP_URL_HOST) ?: '';
            $root = self::registrableDomain($host);

            if ($root !== null) {
                config(['session.domain' => '.'.$root]);
            }
        }

        if (config('app.env') !== 'production') {
            return;
        }

        if (! config('session.domain')) {
            return;
        }

        config([
            'session.secure' => filter_var(
                env('SESSION_SECURE_COOKIE', true),
                FILTER_VALIDATE_BOOL,
            ),
            'session.same_site' => env('SESSION_SAME_SITE', 'none'),
        ]);
    }

    private static function registrableDomain(string $host): ?string
    {
        $host = strtolower(trim($host));

        if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        $parts = explode('.', $host);

        if (count($parts) < 2) {
            return null;
        }

        return implode('.', array_slice($parts, -2));
    }
}
