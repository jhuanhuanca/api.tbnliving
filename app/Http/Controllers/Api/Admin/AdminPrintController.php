<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Withdrawal;
use App\Models\WalletTransaction;
use App\Services\InvoiceService;
use App\Support\InvoicePrintPresenter;
use App\Support\WalletSettingsPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Vistas HTML para imprimir (el navegador puede “Guardar como PDF”).
 *
 * Nota: estos endpoints viven bajo auth:sanctum + mlm.admin.
 */
class AdminPrintController extends Controller
{
    public function orderInvoice(Request $request, Order $order, InvoiceService $invoiceService): Response
    {
        $order->loadMissing(['user', 'items.product', 'items.package', 'invoice.items']);

        $invoice = $order->invoice ?: $invoiceService->emitirDesdeOrdenSiNoExiste($order);

        if (! $invoice) {
            abort(404, 'Factura no encontrada para este pedido.');
        }

        $invoice->loadMissing(['items.product', 'items.package', 'user', 'order']);

        return $this->invoiceHtmlResponse($invoice, $order);
    }

    public function invoice(Request $request, Invoice $invoice): Response
    {
        $invoice->loadMissing([
            'items.product',
            'items.package',
            'user',
            'order.items.product',
            'order.items.package',
            'order.user',
        ]);

        return $this->invoiceHtmlResponse($invoice, $invoice->order);
    }

    /**
     * Renderiza print.invoice con array `print` (fuente de verdad) y modelos legacy opcionales.
     */
    private function invoiceHtmlResponse(Invoice $invoice, ?Order $order = null): Response
    {
        try {
            $viewData = InvoicePrintPresenter::viewPayload($invoice, $order);
        } catch (Throwable $e) {
            report($e);

            abort(500, 'No se pudo generar la vista de factura.');
        }

        return response()
            ->view('print.invoice', $viewData)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function withdrawal(Request $request, Withdrawal $withdrawal): Response
    {
        $withdrawal->loadMissing(['user', 'processor']);

        $walletSettings = WalletSettingsPresenter::fromUser($withdrawal->user);

        $ledger = WalletTransaction::query()
            ->where('withdrawal_id', $withdrawal->id)
            ->orderBy('id')
            ->get(['id', 'type', 'amount', 'description', 'created_at']);

        return response()
            ->view('print.withdrawal', [
                'withdrawal' => $withdrawal,
                'ledger' => $ledger,
                'walletSettings' => $walletSettings,
            ])
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
