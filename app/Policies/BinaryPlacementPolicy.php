<?php

namespace App\Policies;

use App\Models\BinaryPlacement;
use App\Models\User;

class BinaryPlacementPolicy
{
    public function create(User $user): bool
    {
        return $user->canAccessAdminPanel();
    }

    public function update(User $user, BinaryPlacement $binaryPlacement): bool
    {
        return $user->canAccessAdminPanel();
    }
}
