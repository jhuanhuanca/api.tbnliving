<?php

namespace App\Services\Invoicing;

use App\Contracts\ElectronicInvoiceGatewayInterface;
use App\Models\Invoice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP genérico para API de impuestos / facturación electrónica.
 * Ajusta payload según tu proveedor en config mlm.invoice.electronic.
 */
class HttpElectronicInvoiceGateway implements ElectronicInvoiceGatewayInterface
{
    public function submit(Invoice $invoice): array
    {
        $cfg = config('mlm.invoice.electronic', []);
        $url = (string) ($cfg['api_url'] ?? '');
        if ($url === '') {
            return [
                'cuf' => null,
                'status' => 'pending_integration',
                'message' => 'MLM_INVOICE_ELECTRONIC_URL no configurada.',
            ];
        }

        $invoice->loadMissing(['items', 'user', 'order']);

        $payload = [
            'numero_factura' => $invoice->numero_factura,
            'fecha_emision' => $invoice->fecha_emision,
            'issuer' => [
                'nit' => $invoice->issuer_nit,
                'business_name' => $invoice->issuer_business_name,
                'authorization_code' => $invoice->authorization_code,
            ],
            'customer' => [
                'document' => $invoice->customer_document,
                'business_name' => $invoice->customer_business_name,
                'email' => $invoice->user?->email,
            ],
            'order_id' => $invoice->order_id,
            'sub_total' => (string) $invoice->sub_total,
            'tax_amount' => (string) $invoice->tax_amount,
            'tax_rate' => (string) $invoice->tax_rate,
            'total' => (string) $invoice->total,
            'items' => $invoice->items->map(fn ($line) => [
                'descripcion' => $line->descripcion,
                'cantidad' => (int) $line->cantidad,
                'unit_precio' => (string) $line->unit_precio,
                'total_precio' => (string) $line->total_precio,
                'product_id' => $line->product_id,
                'package_id' => $line->package_id,
            ])->values()->all(),
        ];

        $timeout = (int) ($cfg['timeout_seconds'] ?? 30);
        $request = Http::timeout(max(5, $timeout))
            ->acceptJson()
            ->asJson();

        $token = (string) ($cfg['api_token'] ?? '');
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        try {
            $response = $request->post($url, $payload);
        } catch (\Throwable $e) {
            Log::warning('invoice.electronic.http_failed', [
                'invoice_id' => $invoice->id,
                'message' => $e->getMessage(),
            ]);

            return [
                'cuf' => null,
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }

        if (! $response->successful()) {
            Log::warning('invoice.electronic.api_error', [
                'invoice_id' => $invoice->id,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return [
                'cuf' => null,
                'status' => 'failed',
                'message' => 'API impuestos respondió '.$response->status(),
            ];
        }

        $body = $response->json() ?? [];
        $cuf = $body['cuf'] ?? $body['data']['cuf'] ?? $body['CUF'] ?? null;
        $status = (string) ($body['status'] ?? $body['data']['status'] ?? 'issued');

        return [
            'cuf' => $cuf ? (string) $cuf : null,
            'status' => $status,
            'message' => (string) ($body['message'] ?? ''),
        ];
    }
}
