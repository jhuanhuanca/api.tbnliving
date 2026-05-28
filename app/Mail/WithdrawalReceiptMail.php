<?php

namespace App\Mail;

use App\Models\Withdrawal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WithdrawalReceiptMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Withdrawal $withdrawal,
        public string $receiptHtml,
    ) {
        $this->onQueue(config('mlm.queues.mail', 'default'));
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Comprobante de retiro #'.$this->withdrawal->id.' — TBN Living',
        );
    }

    public function content(): Content
    {
        $this->withdrawal->loadMissing('user');

        return new Content(
            view: 'emails.withdrawal-receipt',
            with: [
                'withdrawal' => $this->withdrawal,
                'customerName' => $this->withdrawal->user?->name ?? 'Socio',
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->receiptHtml, 'comprobante-retiro-'.$this->withdrawal->id.'.html')
                ->withMime('text/html'),
        ];
    }
}
