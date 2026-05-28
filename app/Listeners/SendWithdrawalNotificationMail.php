<?php

namespace App\Listeners;

use App\Events\WithdrawalCreated;
use App\Events\WithdrawalStatusChanged;
use App\Mail\WithdrawalCreatedMail;
use App\Mail\WithdrawalStatusMail;
use App\Models\Withdrawal;
use App\Services\Mail\DocumentEmailService;
use Illuminate\Support\Facades\Mail;

class SendWithdrawalNotificationMail
{
    public function handleCreated(WithdrawalCreated $event): void
    {
        $w = $event->withdrawal->loadMissing('user');
        if (! $w->user?->email) {
            return;
        }

        Mail::to($w->user->email)->send(new WithdrawalCreatedMail($w));
    }

    public function handleStatusChanged(WithdrawalStatusChanged $event): void
    {
        $w = $event->withdrawal->loadMissing('user');
        if (! $w->user?->email) {
            return;
        }

        if ($event->status === 'completed') {
            app(DocumentEmailService::class)->sendWithdrawalReceiptToCustomer($w);

            return;
        }

        if (! in_array($event->status, ['approved', 'rejected'], true)) {
            return;
        }

        Mail::to($w->user->email)->send(new WithdrawalStatusMail($w, $event->status));
    }
}
