<?php

namespace App\Policies;

use App\Models\User;

class SettingPolicy
{
    /**
     * Determine whether the user can see the business rules and drop-off timing.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can change the business rules.
     */
    public function update(User $user): bool
    {
        return $user->isAdmin();
    }
}
