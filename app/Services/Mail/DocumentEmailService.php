<?php

namespace App\Services\Mail;

use App\Mail\OrderInvoiceMail;
use App\Mail\WithdrawalReceiptMail;
use App\Models\Invoice;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DocumentEmailService
{
    public function __construct(
        protected DocumentHtmlRenderer $renderer,
    ) {}

    public function sendOrderInvoiceToCustomer(Invoice $invoice): bool
    {
        if (! config('mlm.invoice.email.send_order_invoice', true)) {
            return false;
        }

        $invoice->loadMissing(['user', 'order']);
        $email = $invoice->user?->email;

        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            $html = $this->renderer->orderInvoiceHtml($invoice, $invoice->order);
            Mail::to($email)->queue(new OrderInvoiceMail($invoice, $html));

            return true;
        } catch (\Throwable $e) {
            Log::error('mail.order_invoice.failed', [
                'invoice_id' => $invoice->id,
                'order_id' => $invoice->order_id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function sendWithdrawalReceiptToCustomer(Withdrawal $withdrawal): bool
    {
        if (! config('mlm.invoice.email.send_withdrawal_receipt', true)) {
            return false;
        }

        $withdrawal->loadMissing('user');
        $email = $withdrawal->user?->email;

        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if ($withdrawal->estado !== Withdrawal::ESTADO_COMPLETADO) {
            return false;
        }

        try {
            $html = $this->renderer->withdrawalReceiptHtml($withdrawal);
            Mail::to($email)->queue(new WithdrawalReceiptMail($withdrawal, $html));

            return true;
        } catch (\Throwable $e) {
            Log::error('mail.withdrawal_receipt.failed', [
                'withdrawal_id' => $withdrawal->id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
