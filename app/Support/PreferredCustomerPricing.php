<?php

namespace App\Support;

use App\Models\Product;

/**
 * Precio público = price_cliente_preferente (catálogo).
 * Cliente preferente paga 10% de descuento sobre precio público.
 * Bono venta directa al patrocinador = 10% del precio público por unidad.
 */
final class PreferredCustomerPricing
{
    public const PUBLIC_DISCOUNT_RATE = '0.10';

    public const SPONSOR_COMMISSION_RATE = '0.10';

    /** Markup sobre precio socio si no hay precio público en catálogo (~278/198). */
    public const PUBLIC_FALLBACK_MULTIPLIER = '1.404';

    public static function publicPrice(Product $product): string
    {
        if ($product->price_cliente_preferente !== null && $product->price_cliente_preferente !== '') {
            return bcadd((string) $product->price_cliente_preferente, '0', 2);
        }

        return bcadd(bcmul((string) $product->price, self::PUBLIC_FALLBACK_MULTIPLIER, 4), '0', 2);
    }

    public static function preferenteUnitPrice(Product $product): string
    {
        $public = self::publicPrice($product);
        $discounted = bcmul($public, bcsub('1', self::PUBLIC_DISCOUNT_RATE, 4), 4);

        return bcadd($discounted, '0', 2);
    }

    public static function sponsorCommissionUnit(Product $product): string
    {
        $public = self::publicPrice($product);

        return bcadd(bcmul($public, self::SPONSOR_COMMISSION_RATE, 4), '0', 2);
    }

    public static function socioUnitPrice(Product $product): string
    {
        return bcadd((string) $product->price, '0', 2);
    }
}
