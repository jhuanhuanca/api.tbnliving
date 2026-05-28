<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderInvoiceMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public string $invoiceHtml,
    ) {
        $this->onQueue(config('mlm.queues.mail', 'default'));
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Factura '.$this->invoice->numero_factura.' — TBN Living',
        );
    }

    public function content(): Content
    {
        $this->invoice->loadMissing('user');

        return new Content(
            view: 'emails.order-invoice',
            with: [
                'invoice' => $this->invoice,
                'customerName' => $this->invoice->customer_business_name
                    ?: $this->invoice->user?->name
                    ?: 'Cliente',
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $filename = 'factura-'.preg_replace('/[^a-zA-Z0-9._-]+/', '_', $this->invoice->numero_factura).'.html';

        return [
            Attachment::fromData(fn () => $this->invoiceHtml, $filename)
                ->withMime('text/html'),
        ];
    }
}
