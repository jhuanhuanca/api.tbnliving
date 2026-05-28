<?php

namespace App\Services;

use App\Contracts\ElectronicInvoiceGatewayInterface;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Services\Mail\DocumentEmailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Emisión idempotente de factura a partir de un pedido completado.
 */
class InvoiceService
{
    public function __construct(
        protected ElectronicInvoiceGatewayInterface $electronicGateway,
    ) {}

    public function emitirDesdeOrdenSiNoExiste(Order $order): ?Invoice
    {
        $existing = Invoice::query()->where('order_id', $order->id)->first();
        if ($existing) {
            return $existing->loadMissing('items');
        }

        $order->loadMissing(['user', 'items.product', 'items.package']);

        $invoice = DB::transaction(function () use ($order) {
            $taxRatePct = (string) config('mlm.invoice.default_tax_rate', '0');
            $sub = '0';
            foreach ($order->items as $line) {
                $sub = bcadd($sub, (string) $line->precio_total, 2);
            }

            $tax = '0';
            if (bccomp($taxRatePct, '0', 4) === 1) {
                $tax = bcmul($sub, bcdiv($taxRatePct, '100', 6), 2);
            }
            $total = bcadd($sub, $tax, 2);

            $buyer = $order->user;
            $electronicEnabled = (bool) config('mlm.invoice.electronic.enabled', false);

            $created = Invoice::query()->create([
                'user_id' => $order->user_id,
                'order_id' => $order->id,
                'numero_factura' => 'INV-'.$order->id.'-'.substr((string) $order->uuid, 0, 8),
                'issuer_nit' => config('mlm.invoice.issuer_nit'),
                'issuer_business_name' => config('mlm.invoice.issuer_name'),
                'customer_document' => $buyer?->document_id,
                'customer_business_name' => $buyer?->name,
                'authorization_code' => config('mlm.invoice.authorization_code'),
                'cuf' => null,
                'electronic_invoice_status' => $electronicEnabled ? 'pending_integration' : 'local_only',
                'fecha_emision' => now()->toDateString(),
                'sub_total' => $sub,
                'tax_amount' => $tax,
                'tax_rate' => $taxRatePct,
                'total' => $total,
                'impuestos' => bccomp($tax, '0', 2) === 1 ? 'Tasa '.$taxRatePct.'%' : null,
                'estado' => 'emitida',
            ]);

            foreach ($order->items as $line) {
                $m = $line->meta ?? [];
                $desc = $line->product?->name
                    ?? $line->package?->name
                    ?? (! empty($m['label']) ? (string) $m['label'] : null)
                    ?? (! empty($m['founder_package']) ? 'Paquete Fundador ('.(string) $m['founder_package'].')' : null)
                    ?? 'Ítem';
                InvoiceItem::query()->create([
                    'invoice_id' => $created->id,
                    'product_id' => $line->product_id,
                    'package_id' => $line->package_id,
                    'descripcion' => $desc,
                    'cantidad' => (int) $line->cantidad,
                    'unit_precio' => (string) $line->precio_unitario,
                    'total_precio' => (string) $line->precio_total,
                ]);
            }

            return $created;
        });

        $invoice = $this->enviarFacturaElectronicaSiAplica($invoice->fresh(['items', 'user', 'order']));

        $this->enviarFacturaPorCorreoSiAplica($invoice, $order);

        return $invoice;
    }

    protected function enviarFacturaPorCorreoSiAplica(Invoice $invoice, Order $order): void
    {
        try {
            app(DocumentEmailService::class)->sendOrderInvoiceToCustomer($invoice->loadMissing(['user', 'order']));
        } catch (\Throwable $e) {
            Log::warning('invoice.email.send_failed', [
                'invoice_id' => $invoice->id,
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function enviarFacturaElectronicaSiAplica(Invoice $invoice): Invoice
    {
        if (! config('mlm.invoice.electronic.enabled', false)) {
            return $invoice;
        }

        if (in_array($invoice->electronic_invoice_status, ['issued', 'sent', 'accepted'], true)) {
            return $invoice;
        }

        try {
            $result = $this->electronicGateway->submit($invoice);
            $invoice->update([
                'cuf' => $result['cuf'] ?? $invoice->cuf,
                'electronic_invoice_status' => $result['status'] ?? 'pending_integration',
            ]);
        } catch (\Throwable $e) {
            Log::error('invoice.electronic.unhandled', [
                'invoice_id' => $invoice->id,
                'message' => $e->getMessage(),
            ]);
            $invoice->update(['electronic_invoice_status' => 'failed']);
        }

        return $invoice->fresh('items');
    }
}
