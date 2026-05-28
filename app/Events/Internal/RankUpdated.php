<?php

namespace App\Events\Internal;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RankUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(public User $user, public ?int $oldRankId, public ?int $newRankId) {}
}

