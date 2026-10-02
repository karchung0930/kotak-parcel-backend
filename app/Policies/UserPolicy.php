<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the user can list user accounts.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can view the account.
     */
    public function view(User $user, User $model): bool
    {
        return $user->isAdmin() || $user->is($model);
    }

    /**
     * Determine whether the user can create accounts (staff, drivers and admins).
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the account, including its role and active flag.
     */
    public function update(User $user, User $model): bool
    {
        return $user->isAdmin();
    }
}
