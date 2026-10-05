<?php

namespace App\Actions\Delivery;

use App\Enums\OrderStatus;
use App\Events\DeliveryAssigned;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderStatusService;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignDriver
{
    /**
     * Create a new action instance.
     */
    public function __construct(
        private OrderStatusService $statuses,
        private Settings $settings,
    ) {}

    /**
     * Schedule a paid parcel with an active driver, reschedule a failed
     * delivery, or reassign a delivery to another driver or day before the
     * driver collects it. Once committed, DeliveryAssigned lets the drivers
     * whose run changed know.
     *
     * @throws ValidationException
     * @throws InvalidStatusTransition
     */
    public function handle(Order $order, User $admin, User $driver, CarbonInterface $scheduledFor): Order
    {
        if (! $driver->isDriver() || ! $driver->is_active) {
            throw ValidationException::withMessages([
                'driver_id' => 'Choose an active driver.',
            ]);
        }

        if ($scheduledFor->toDateString() < today(config()->string('kotak.timezone'))->toDateString()) {
            throw ValidationException::withMessages([
                'scheduled_for' => 'The delivery date cannot be in the past.',
            ]);
        }

        return DB::transaction(function () use ($order, $admin, $driver, $scheduledFor): Order {
            $order = $order->freshLocked();

            if (! $order->canBeAssigned()) {
                throw $order->status === OrderStatus::DeliveryFailed
                    ? new InvalidStatusTransition(sprintf(
                        'This parcel has reached the maximum of %d delivery attempts and must be returned to the sender.',
                        $this->settings->maxFailedAttempts(),
                    ))
                    : InvalidStatusTransition::between($order->status, OrderStatus::Assigned);
            }

            $sameDay = $order->scheduled_for?->toDateString() === $scheduledFor->toDateString();

            if ($order->status === OrderStatus::Assigned && $sameDay && $order->isAssignedTo($driver)) {
                throw ValidationException::withMessages([
                    'driver_id' => 'This parcel is already scheduled with this driver on that day. Choose another driver or date.',
                ]);
            }

            // The run the delivery is on now, read under the lock before it changes.
            // A failed delivery is on nobody's run: that job ended with the attempt.
            $previousDriver = $order->status === OrderStatus::Assigned ? $order->driver : null;
            $previousDate = $previousDriver !== null ? $order->scheduled_for : null;

            // The delivery joins the end of that driver's run for the day; the
            // driver can move it up on My jobs (MoveJob). Reading the last place
            // locks the run's stops, so a deadlock with another change to the
            // same run is tried again (up to three attempts).
            $order = $this->statuses->transition(
                $order,
                OrderStatus::Assigned,
                $admin,
                $this->note($order, $scheduledFor, $sameDay),
                [
                    'driver_id' => $driver->id,
                    'scheduled_for' => $scheduledFor->toDateString(),
                    'route_position' => Order::nextRoutePosition($driver, $scheduledFor),
                    'route_date' => $scheduledFor->toDateString(),
                ],
            );

            DeliveryAssigned::dispatch($order, $driver, $previousDriver, $previousDate);

            return $order;
        }, 3);
    }

    /**
     * Get the history note, which the customer also sees (without the driver's name).
     */
    private function note(Order $order, CarbonInterface $scheduledFor, bool $sameDay): string
    {
        $day = $scheduledFor->format('j M Y');

        return match (true) {
            $order->status !== OrderStatus::Assigned => "Scheduled for delivery on {$day}.",
            $sameDay => "Reassigned to another driver for delivery on {$day}.",
            default => "Rescheduled for delivery on {$day}.",
        };
    }
}
