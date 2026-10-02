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
     * Create a new event instance.
     */
    public function __construct(
        public Order $order,
        public ?OrderStatus $from,
        public OrderStatus $to,
    ) {}
}
