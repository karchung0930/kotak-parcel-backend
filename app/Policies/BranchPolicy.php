<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    /**
     * Determine whether the user can list branches (public).
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the branch (public).
     */
    public function view(?User $user, Branch $branch): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create branches.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the branch, including deactivating it.
     */
    public function update(User $user, Branch $branch): bool
    {
        return $user->isAdmin();
    }
}
