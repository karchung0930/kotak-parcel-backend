<?php

namespace App\Policies;

use App\Models\RateImport;
use App\Models\User;

/**
 * Only admins import rates, as only they manage rate cards. Any admin can
 * pick up an import another admin started.
 *
 * Whether an import is in the right state for a step (mapping, choosing a
 * sheet, making the draft) is checked by the action, under a lock, so an
 * admin acting on a page gone stale is told why.
 */
class RateImportPolicy
{
    /**
     * Determine whether the user can list the recent imports.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can see an import.
     */
    public function view(User $user, RateImport $rateImport): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can upload a file.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can work on an import: choose a sheet,
     * confirm the mapping and make the draft.
     */
    public function update(User $user, RateImport $rateImport): bool
    {
        return $user->isAdmin();
    }
}
