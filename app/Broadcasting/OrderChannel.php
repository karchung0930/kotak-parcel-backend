<?php

namespace App\Broadcasting;

use App\Models\Order;
use App\Models\User;

/**
 * The private channel of a customer's order page, which hears the parcel's
 * stop while it is out for delivery (App\Events\DeliveryProgressUpdated).
 */
class OrderChannel
{
    /**
     * Authenticate the user's access to the channel: only the customer who
     * placed the order, on the same terms as their order page (a verified
     * email address).
     */
    public function join(User $user, Order $order): bool
    {
        return $user->isCustomer() && $user->hasVerifiedEmail() && $order->isOwnedBy($user);
    }
}
