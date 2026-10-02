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
     * Cancel orders that were never dropped off in time and return how many were cancelled.
     */
    public function handle(): int
    {
        $days = config()->integer('kotak.unclaimed_order_days');
        $cancelled = 0;

        foreach (Order::query()->unclaimed()->lazyById() as $order) {
            try {
                $this->cancelOrder->handle($order, null, "Not dropped off within {$days} days.");
                $cancelled++;
            } catch (InvalidStatusTransition) {
                // Dropped off while the job was running: nothing to expire.
            }
        }

        return $cancelled;
    }
}
