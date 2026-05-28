<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Withdrawal;

class WithdrawalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdminPanel();
    }

    public function approve(User $user, Withdrawal $withdrawal): bool
    {
        return $user->canAccessAdminPanel();
    }

    public function reject(User $user, Withdrawal $withdrawal): bool
    {
        return $user->canAccessAdminPanel();
    }
}
