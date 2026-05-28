<?php

namespace App\Contracts;

use App\Models\Invoice;

/**
 * Puente hacia el proveedor de facturación electrónica (SIN / impuestos).
 */
interface ElectronicInvoiceGatewayInterface
{
    /**
     * Envía la factura al servicio externo y actualiza CUF / estado en BD.
     *
     * @return array{cuf: ?string, status: string, message?: string}
     */
    public function submit(Invoice $invoice): array;
}
