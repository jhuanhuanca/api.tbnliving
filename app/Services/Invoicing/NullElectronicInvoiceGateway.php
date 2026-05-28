<?php

namespace App\Services\Invoicing;

use App\Contracts\ElectronicInvoiceGatewayInterface;
use App\Models\Invoice;

/** Sin API externa: deja la factura en estado local emitida. */
class NullElectronicInvoiceGateway implements ElectronicInvoiceGatewayInterface
{
    public function submit(Invoice $invoice): array
    {
        return [
            'cuf' => $invoice->cuf,
            'status' => $invoice->electronic_invoice_status ?? 'local_only',
            'message' => 'Integración electrónica desactivada.',
        ];
    }
}
