<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderStatusService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class CancelOrder
{
    /**
     * Create a new action instance.
     */
    public function __construct(
        private OrderStatusService $statuses,
    ) {}

    /**
     * Cancel an order and record why.
     *
     * Customers (and the expiry job, with no actor) may cancel before drop-off.
     * Branch staff cancel after weighing when the customer refuses the final price.
     *
     * @throws AuthorizationException
     * @throws InvalidStatusTransition
     */
    public function handle(Order $order, ?User $actor, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $actor, $reason): Order {
            $order = $order->freshLocked();

            if ($actor?->isStaff()) {
                if ($order->status !== OrderStatus::DroppedOff) {
                    throw new InvalidStatusTransition('Branch staff can only cancel a parcel after it has been weighed.');
                }

                $reason = filled($reason) ? $reason : 'Customer declined the final price.';
            } else {
                if ($actor && ! $order->isOwnedBy($actor)) {
                    throw new AuthorizationException('You cannot cancel this order.');
                }

                if ($order->status !== OrderStatus::Created) {
                    throw new InvalidStatusTransition('Orders can only be cancelled before the parcel is dropped off.');
                }
            }

            return $this->statuses->transition($order, OrderStatus::Cancelled, $actor, $reason);
        });
    }
}
