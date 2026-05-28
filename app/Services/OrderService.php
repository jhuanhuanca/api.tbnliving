<?php

namespace App\Services;

use App\Jobs\ProcessOrderMlmAccrualsJob;
use App\Models\Order;

/**
 * Orquestación post-compra: acreditaciones MLM (factura se emite al completar el pedido).
 */
class OrderService
{
    public function procesarOrdenFinalizada(Order $order): void
    {
        $order->loadMissing(['items.product', 'items.package', 'user']);
        ProcessOrderMlmAccrualsJob::dispatch($order->id);
    }
}
