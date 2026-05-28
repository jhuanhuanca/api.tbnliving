<?php

namespace App\Events;

use App\Models\Withdrawal;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WithdrawalStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Withdrawal $withdrawal,
        public string $status,
    ) {}
}
