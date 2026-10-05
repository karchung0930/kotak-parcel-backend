<?php

namespace App\Events;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An order moved to a new status. Dispatched only after the change is committed,
 * so listeners never act on a change that was rolled back.
 */
class OrderStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * Whether the delivery was only handed to another driver on the same day.
     */
    public readonly bool $driverSwap;

    /**
     * Create a new event instance.
     *
     * OrderStatusService creates it straight after saving the order, while
     * the order still knows what that save changed, so whether this was a
     * driver swap is worked out here and travels with the event, also to
     * queued listeners, which get the order fresh from the database.
     */
    public function __construct(
        public Order $order,
        public ?OrderStatus $from,
        public OrderStatus $to,
    ) {
        $this->driverSwap = $from === $to && ! $order->wasChanged('scheduled_for');
    }

    /**
     * Determine if a delivery was only handed to another driver on the same
     * day, which changes nothing for the customer or the receiver.
     */
    public function isDriverSwap(): bool
    {
        return $this->driverSwap;
    }
}
