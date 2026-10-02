<?php

namespace App\Actions\Delivery;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderStatusService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class MarkPickedUp
{
    /**
     * Create a new action instance.
     */
    public function __construct(
        private OrderStatusService $statuses,
    ) {}

    /**
     * Record that the assigned driver collected the parcel from the branch.
     *
     * @throws AuthorizationException
     * @throws InvalidStatusTransition
     */
    public function handle(Order $order, User $driver): Order
    {
        return DB::transaction(function () use ($order, $driver): Order {
            $order = $order->freshLocked();

            if (! $order->isAssignedTo($driver)) {
                throw new AuthorizationException('This delivery is not assigned to you.');
            }

            return $this->statuses->transition($order, OrderStatus::PickedUp, $driver);
        });
    }
}
