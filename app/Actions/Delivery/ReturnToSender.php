<?php

namespace App\Actions\Delivery;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderStatusService;

class ReturnToSender
{
    /**
     * Create a new action instance.
     */
    public function __construct(
        private OrderStatusService $statuses,
    ) {}

    /**
     * Close a failed delivery by returning the parcel to the sender.
     *
     * @throws InvalidStatusTransition
     */
    public function handle(Order $order, User $admin, ?string $note = null): Order
    {
        return $this->statuses->transition(
            $order,
            OrderStatus::ReturnedToSender,
            $admin,
            filled($note) ? $note : 'Returned to the sender after unsuccessful delivery.',
        );
    }
}
