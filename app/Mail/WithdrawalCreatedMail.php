<?php

namespace App\Mail;

use App\Models\Withdrawal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WithdrawalCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Withdrawal $withdrawal) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Solicitud de retiro registrada — TBN Living',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.withdrawal-created',
        );
    }
}
