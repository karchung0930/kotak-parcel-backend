<?php

namespace App\Actions\Delivery;

use App\Enums\DeliveryFailureReason;
use App\Enums\DeliveryOutcome;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderStatusService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class RecordDeliveryFailure
{
    /**
     * Create a new action instance.
     */
    public function __construct(
        private OrderStatusService $statuses,
    ) {}

    /**
     * Record a failed delivery attempt. An admin then reschedules or returns the parcel.
     *
     * Only the reason's label goes into the public history; the driver's
     * free-text note stays on the attempt record.
     *
     * @throws AuthorizationException
     * @throws InvalidStatusTransition
     */
    public function handle(Order $order, User $driver, DeliveryFailureReason $reason, ?string $note = null): Order
    {
        return DB::transaction(function () use ($order, $driver, $reason, $note): Order {
            $order = $order->freshLocked();

            if (! $order->isAssignedTo($driver)) {
                throw new AuthorizationException('This delivery is not assigned to you.');
            }

            $order->deliveryAttempts()->forceCreate([
                'driver_id' => $driver->id,
                'outcome' => DeliveryOutcome::Failed,
                'failure_reason' => $reason,
                'note' => filled($note) ? trim((string) $note) : null,
                'attempted_at' => now(),
            ]);

            // The stop leaves the driver's run; a reschedule puts it on a new one.
            return $this->statuses->transition($order, OrderStatus::DeliveryFailed, $driver, $reason->label(), [
                'route_position' => null,
                'route_date' => null,
            ]);
        });
    }
}
