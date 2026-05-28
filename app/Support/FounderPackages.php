<?php

namespace App\Support;

final class FounderPackages
{
    public const SLUGS = ['basico', 'avanzado', 'profesional', 'fundador'];

    /** @return array<string, array{price_bob: string, pv: string}> */
    public static function map(): array
    {
        return [
            'basico' => ['price_bob' => '1050', 'pv' => '100'],
            'avanzado' => ['price_bob' => '2700', 'pv' => '300'],
            'profesional' => ['price_bob' => '5400', 'pv' => '600'],
            'fundador' => ['price_bob' => '10800', 'pv' => '1200'],
        ];
    }

    public static function isValidSlug(string $slug): bool
    {
        return in_array($slug, self::SLUGS, true);
    }

    public static function pv(string $slug): string
    {
        return self::map()[$slug]['pv'] ?? '0';
    }

    public static function priceBob(string $slug): string
    {
        return self::map()[$slug]['price_bob'] ?? '0';
    }
}
