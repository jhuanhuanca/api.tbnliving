<?php

namespace App\Services\Mail;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Support\InvoicePrintPresenter;
use Illuminate\Support\Facades\View;

/**
 * Renderiza las mismas plantillas HTML que el panel admin (impresión / PDF).
 */
class DocumentHtmlRenderer
{
    public function orderInvoiceHtml(Invoice $invoice, ?Order $order = null): string
    {
        $invoice->loadMissing(['items.product', 'items.package', 'user', 'order']);

        return View::make('print.invoice', InvoicePrintPresenter::viewPayload($invoice, $order))->render();
    }

    public function withdrawalReceiptHtml(Withdrawal $withdrawal): string
    {
        $withdrawal->loadMissing(['user', 'processor']);

        $ledger = WalletTransaction::query()
            ->where('withdrawal_id', $withdrawal->id)
            ->orderBy('id')
            ->get(['id', 'type', 'amount', 'description', 'created_at']);

        return View::make('print.withdrawal', [
            'withdrawal' => $withdrawal,
            'ledger' => $ledger,
        ])->render();
    }
}
