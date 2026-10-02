<?php

namespace App\Policies;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /**
     * Determine whether the user can view the order.
     *
     * Staff and admins see every order and customers only their own. Drivers
     * see a delivery assigned to them only while it is open, so finished jobs
     * cannot be reopened to look up the receiver's contact details.
     */
    public function view(User $user, Order $order): bool
    {
        return $user->isStaff()
            || ($user->isDriver() && $order->isAssignedTo($user) && $order->status->isActiveJob())
            || ($user->isCustomer() && $order->isOwnedBy($user));
    }

    /**
     * Determine whether the user can create orders.
     */
    public function create(User $user): bool
    {
        return $user->isCustomer();
    }

    /**
     * Determine whether the user can cancel the order.
     *
     * Customers cancel their own order before drop-off; branch staff cancel
     * after weighing when the customer refuses the final price.
     */
    public function cancel(User $user, Order $order): bool
    {
        if ($user->isStaff()) {
            return $order->status === OrderStatus::DroppedOff;
        }

        return $user->isCustomer()
            && $order->isOwnedBy($user)
            && $order->status === OrderStatus::Created;
    }

    /**
     * Determine whether the user can process the order at a branch counter (drop-off and payment).
     */
    public function process(User $user, Order $order): bool
    {
        return $user->isStaff();
    }

    /**
     * Determine whether the user can assign the delivery to a driver.
     */
    public function assign(User $user, Order $order): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can return the parcel to its sender.
     */
    public function returnToSender(User $user, Order $order): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can pick up and deliver the parcel.
     */
    public function deliver(User $user, Order $order): bool
    {
        return $user->isDriver() && $order->isAssignedTo($user);
    }

    /**
     * Determine whether the user can see the proof of delivery photo.
     */
    public function viewProof(User $user, Order $order): bool
    {
        // The assigned driver keeps access to the photo they took after the job closes.
        return $this->view($user, $order) || ($user->isDriver() && $order->isAssignedTo($user));
    }
}
