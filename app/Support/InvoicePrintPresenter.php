<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Carbon;

/**
 * Datos normalizados para la vista HTML/PDF de factura.
 *
 * Contrato de vista: usar siempre el array `print` ($p en Blade).
 * Las claves legacy `invoice` y `order` solo existen por compatibilidad con vistas compiladas en caché.
 */
class InvoicePrintPresenter
{
    public function __construct(
        public Invoice $invoice,
        public ?Order $order = null,
    ) {
        $this->invoice->loadMissing(['items.product', 'items.package', 'user', 'order']);
        $this->order ??= $this->invoice->order;
        $this->order?->loadMissing(['items.product', 'items.package', 'user', 'paymentConfirmedBy']);
    }

    /**
     * Payload único para print.invoice (HTML / PDF vía navegador).
     *
     * @return array{print: array<string, mixed>, invoice: Invoice, order: Order|null}
     */
    public static function viewPayload(Invoice $invoice, ?Order $order = null): array
    {
        $presenter = new self($invoice, $order);

        return [
            'print' => $presenter->toArray(),
            'invoice' => $presenter->invoice,
            'order' => $presenter->order,
        ];
    }

    public function toArray(): array
    {
        $lines = $this->buildLines();
        $discountTotal = $this->sumColumn($lines, 'discount');
        $subTotal = bcadd((string) $this->invoice->sub_total, '0', 2);
        $grossSubtotal = bcadd($subTotal, $discountTotal, 2);

        return [
            'currency' => (string) config('mlm.currency', 'BOB'),
            'currency_label' => $this->currencyLabel(),
            'issuer' => [
                'name' => $this->invoice->issuer_business_name ?: config('mlm.invoice.issuer_name', 'TBN Living'),
                'nit' => $this->invoice->issuer_nit ?: config('mlm.invoice.issuer_nit'),
                'authorization' => $this->invoice->authorization_code ?: config('mlm.invoice.authorization_code'),
            ],
            'document' => [
                'number' => $this->invoice->numero_factura,
                'date' => $this->formatDate($this->invoice->fecha_emision),
                'date_iso' => $this->invoice->fecha_emision,
                'order_id' => $this->invoice->order_id,
                'order_uuid' => $this->order?->uuid,
                'order_reference' => $this->order?->reference,
                'order_type' => $this->order?->tipo,
                'cuf' => $this->invoice->cuf,
                'electronic_status' => $this->invoice->electronic_invoice_status,
                'status' => $this->invoice->estado,
                'issued_at' => $this->invoice->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i'),
            ],
            'customer' => [
                'name' => $this->invoice->customer_business_name ?: $this->invoice->user?->name ?: '—',
                'document' => $this->invoice->customer_document ?: $this->invoice->user?->document_id,
                'email' => $this->invoice->user?->email,
                'member_code' => $this->invoice->user?->member_code,
            ],
            'lines' => $lines,
            'totals' => [
                'gross_subtotal' => $grossSubtotal,
                'discount' => $discountTotal,
                'subtotal' => $subTotal,
                'tax_rate' => bcadd((string) ($this->invoice->tax_rate ?? '0'), '0', 2),
                'tax_amount' => bcadd((string) ($this->invoice->tax_amount ?? '0'), '0', 2),
                'total' => bcadd((string) $this->invoice->total, '0', 2),
                'tax_label' => $this->invoice->impuestos,
                'pv_total' => $this->order ? bcadd((string) ($this->order->total_pv ?? '0'), '0', 2) : null,
            ],
            'payment' => [
                'method' => $this->order?->payment_method,
                'method_label' => $this->paymentMethodLabel($this->order?->payment_method),
                'confirmed_at' => $this->order?->payment_confirmed_at
                    ? $this->formatDateTime($this->order->payment_confirmed_at)
                    : null,
                'notes' => $this->order?->payment_admin_notes,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildLines(): array
    {
        $rows = [];
        $index = 0;
        $usedOrderItems = [];

        foreach ($this->invoice->items as $invItem) {
            $index++;
            $orderLine = $this->matchOrderItem($invItem, $usedOrderItems);
            if ($orderLine) {
                $usedOrderItems[$orderLine->id] = true;
            }

            $qty = max(1, (int) $invItem->cantidad);
            $discount = $this->lineDiscount($orderLine, $qty);
            $lineTotal = bcadd((string) $invItem->total_precio, '0', 2);
            $gross = bcadd($lineTotal, $discount, 2);

            $rows[] = [
                'index' => $index,
                'code' => $this->lineCode($invItem, $orderLine),
                'description' => $invItem->descripcion,
                'type' => $this->lineType($invItem, $orderLine),
                'quantity' => $qty,
                'unit_price' => bcadd((string) $invItem->unit_precio, '0', 2),
                'list_unit_price' => $this->listUnitPrice($orderLine, $invItem),
                'discount' => $discount,
                'gross' => $gross,
                'subtotal' => $lineTotal,
                'pv' => $orderLine ? bcadd((string) ($orderLine->pv_points ?? '0'), '0', 2) : null,
            ];
        }

        return $rows;
    }

    private function matchOrderItem(InvoiceItem $invItem, array $used): ?OrderItem
    {
        if (! $this->order) {
            return null;
        }

        foreach ($this->order->items as $oi) {
            if (! empty($used[$oi->id])) {
                continue;
            }
            if ($invItem->product_id && (int) $oi->product_id === (int) $invItem->product_id) {
                return $oi;
            }
            if ($invItem->package_id && (int) $oi->package_id === (int) $invItem->package_id) {
                return $oi;
            }
        }

        foreach ($this->order->items as $oi) {
            if (! empty($used[$oi->id])) {
                continue;
            }
            $desc = trim((string) $invItem->descripcion);
            $label = is_array($oi->meta) && ! empty($oi->meta['label']) ? (string) $oi->meta['label'] : null;
            if ($label && str_contains($desc, $label)) {
                return $oi;
            }
            if ($oi->package_id === null && $oi->product_id === null && $invItem->product_id === null && $invItem->package_id === null) {
                return $oi;
            }
        }

        foreach ($this->order->items as $oi) {
            if (empty($used[$oi->id])) {
                return $oi;
            }
        }

        return null;
    }

    private function lineCode(InvoiceItem $invItem, ?OrderItem $orderLine): string
    {
        if ($invItem->product_id) {
            return 'PRD-'.str_pad((string) $invItem->product_id, 5, '0', STR_PAD_LEFT);
        }
        if ($invItem->package_id) {
            $slug = $invItem->package?->slug ?? $orderLine?->package?->slug;

            return $slug ? strtoupper($slug) : 'PKG-'.$invItem->package_id;
        }
        $meta = is_array($orderLine?->meta) ? $orderLine->meta : [];
        if (! empty($meta['founder_package'])) {
            return 'FND-'.strtoupper((string) $meta['founder_package']);
        }

        return 'SRV-'.$invItem->id;
    }

    private function lineType(InvoiceItem $invItem, ?OrderItem $orderLine): string
    {
        if ($invItem->package_id || $orderLine?->package_id) {
            return 'Paquete';
        }
        if ($invItem->product_id || $orderLine?->product_id) {
            return 'Producto';
        }
        $meta = is_array($orderLine?->meta) ? $orderLine->meta : [];
        if (! empty($meta['founder_package'])) {
            return 'Paquete fundador';
        }

        return 'Servicio';
    }

    private function listUnitPrice(?OrderItem $orderLine, InvoiceItem $invItem): string
    {
        $meta = is_array($orderLine?->meta) ? $orderLine->meta : [];
        if (! empty($meta['preferred_customer_line']) && ! empty($meta['precio_socio_unit'])) {
            return bcadd((string) $meta['precio_socio_unit'], '0', 2);
        }

        return bcadd((string) $invItem->unit_precio, '0', 2);
    }

    private function lineDiscount(?OrderItem $orderLine, int $qty): string
    {
        if (! $orderLine) {
            return '0.00';
        }
        $meta = is_array($orderLine->meta) ? $orderLine->meta : [];
        if (empty($meta['preferred_customer_line'])) {
            return '0.00';
        }

        $socio = bcadd((string) ($meta['precio_socio_unit'] ?? '0'), '0', 2);
        $cliente = bcadd((string) ($meta['precio_cliente_unit'] ?? $orderLine->precio_unitario), '0', 2);
        $perUnit = bcsub($socio, $cliente, 2);
        if (bccomp($perUnit, '0', 2) <= 0) {
            return '0.00';
        }

        return bcmul($perUnit, (string) $qty, 2);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function sumColumn(array $lines, string $key): string
    {
        $sum = '0';
        foreach ($lines as $line) {
            $sum = bcadd($sum, (string) ($line[$key] ?? '0'), 2);
        }

        return bcadd($sum, '0', 2);
    }

    private function currencyLabel(): string
    {
        return match (strtoupper((string) config('mlm.currency', 'BOB'))) {
            'BOB' => 'Bolivianos (Bs.)',
            'USD' => 'Dólares (USD)',
            default => (string) config('mlm.currency', 'BOB'),
        };
    }

    private function paymentMethodLabel(?string $method): ?string
    {
        return match ($method) {
            'efectivo' => 'Efectivo',
            'qr' => 'QR / Pago móvil',
            'transferencia' => 'Transferencia bancaria',
            'wallet', 'wallet_token' => 'Billetera MLM',
            'card' => 'Tarjeta',
            'otro' => 'Otro',
            default => $method,
        };
    }

    private function formatDate(?string $value): string
    {
        if (! $value) {
            return '—';
        }
        try {
            return Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function formatDateTime(mixed $value): string
    {
        try {
            return Carbon::parse($value)->timezone(config('app.timezone'))->format('d/m/Y H:i');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    public static function money(string $amount, bool $withSymbol = true): string
    {
        $formatted = number_format((float) $amount, 2, '.', ',');

        return $withSymbol ? 'Bs '.$formatted : $formatted;
    }

    public static function percent(string $rate): string
    {
        $n = (float) $rate;

        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.').'%';
    }
}
