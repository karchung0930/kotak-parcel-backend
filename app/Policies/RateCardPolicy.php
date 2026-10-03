<?php

namespace App\Policies;

use App\Models\RateCard;
use App\Models\User;

/**
 * Only admins manage rate cards. Anyone sees the current prices on the
 * public pricing page, which needs no policy.
 *
 * The writes (save, publish, withdraw, delete) are authorised with manage:
 * whether the card is still in the right state is checked by the action,
 * under a lock, so an admin who acts on a page gone stale is told why. The
 * per-card abilities say which actions a card's page offers.
 */
class RateCardPolicy
{
    /**
     * Determine whether the user can change rate cards at all.
     */
    public function manage(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can list every version.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can see a version, including drafts.
     */
    public function view(User $user, RateCard $rateCard): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can start a draft.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can edit the card: drafts only, as
     * published cards never change.
     */
    public function update(User $user, RateCard $rateCard): bool
    {
        return $user->isAdmin() && $rateCard->isDraft();
    }

    /**
     * Determine whether the user can publish the draft.
     */
    public function publish(User $user, RateCard $rateCard): bool
    {
        return $user->isAdmin() && $rateCard->isDraft();
    }

    /**
     * Determine whether the user can take a scheduled card back to a draft.
     */
    public function withdraw(User $user, RateCard $rateCard): bool
    {
        return $user->isAdmin() && $rateCard->isScheduled();
    }

    /**
     * Determine whether the user can delete the card: drafts only.
     */
    public function delete(User $user, RateCard $rateCard): bool
    {
        return $user->isAdmin() && $rateCard->isDraft();
    }
}
