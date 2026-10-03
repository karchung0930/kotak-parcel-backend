<?php

namespace App\Actions\Orders;

use App\Exceptions\InvalidStatusTransition;
use App\Models\Order;

class ExpireUnclaimedOrders
{
    /**
     * Create a new action instance.
     */
    public function __construct(
        private CancelOrder $cancelOrder,
    ) {}

    /**
     * Cancel orders still waiting for drop-off after their deadline day and
     * return how many were cancelled.
     */
    public function handle(): int
    {
        $cancelled = 0;

        foreach (Order::query()->unclaimed()->lazyById() as $order) {
            try {
                $this->cancelOrder->handle($order, null, "Not dropped off by {$order->drop_off_deadline?->format('j F Y')}.");
                $cancelled++;
            } catch (InvalidStatusTransition) {
                // Dropped off while the job was running: nothing to expire.
            }
        }

        return $cancelled;
    }
}
