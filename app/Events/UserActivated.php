<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Disparado cuando el usuario alcanza la activación MLM (p. ej. 200 PV en el mes).
 */
class UserActivated
{
    use Dispatchable, SerializesModels;

    public function __construct(public User $user, public string $qualificationMonth) {}
}
