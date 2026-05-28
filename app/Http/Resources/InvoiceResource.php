<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'numero_factura' => $this->numero_factura,
            'fecha_emision' => $this->fecha_emision,
            'estado' => $this->estado,
            'electronic_invoice_status' => $this->electronic_invoice_status,
            'cuf' => $this->cuf,
            'sub_total' => (string) $this->sub_total,
            'tax_amount' => (string) $this->tax_amount,
            'tax_rate' => (string) $this->tax_rate,
            'total' => (string) $this->total,
            'impuestos' => $this->impuestos,
            'customer_document' => $this->customer_document,
            'customer_business_name' => $this->customer_business_name,
            'issuer_nit' => $this->issuer_nit,
            'issuer_business_name' => $this->issuer_business_name,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($line) => [
                'id' => $line->id,
                'descripcion' => $line->descripcion,
                'cantidad' => (int) $line->cantidad,
                'unit_precio' => (string) $line->unit_precio,
                'total_precio' => (string) $line->total_precio,
                'product_id' => $line->product_id,
                'package_id' => $line->package_id,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
