<?php

namespace App\Events\Internal;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AffiliateActivated
{
    use Dispatchable, SerializesModels;

    public function __construct(public User $user, public string $qualificationMonth) {}
}

