<?php

namespace App\Support;

use App\Models\Order;

final class DeliveryNotice
{
    public const LOCAL = 'Entrega local (Santa Cruz): tu pedido se entregará en un plazo de 24 horas hábiles.';

    public const NATIONAL = 'Entrega nacional (otros departamentos): tu pedido se entregará dentro de 48 a 72 horas hábiles.';

    public const PICKUP = 'Recojo personal: coordina la entrega con tu patrocinador o en el punto acordado.';

    public static function isLocal(?string $departamento, ?string $ciudad = null): bool
    {
        $norm = static fn (?string $s) => mb_strtolower(trim(preg_replace('/\p{Mn}/u', '', (string) $s) ?? ''));

        $dep = $norm($departamento);
        $city = $norm($ciudad);

        if (str_contains($dep, 'santa cruz')) {
            return true;
        }

        return str_contains($city, 'santa cruz');
    }

    public static function forOrder(Order $order): string
    {
        $mode = (string) ($order->delivery_mode ?? '');
        $hasShipping = $mode === 'envio'
            || filled($order->shipping_departamento)
            || filled($order->shipping_ciudad)
            || filled($order->shipping_direccion);

        if (! $hasShipping) {
            return self::PICKUP;
        }

        return self::isLocal($order->shipping_departamento, $order->shipping_ciudad)
            ? self::LOCAL
            : self::NATIONAL;
    }
}
