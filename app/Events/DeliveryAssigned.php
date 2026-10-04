<?php

namespace App\Events;

use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A delivery was put on a driver's run: a first assignment, a reschedule or
 * a reassignment. Dispatched only after the change is committed.
 *
 * The run it was on before is part of the event because the order cannot
 * tell by then: its last save already replaced the driver and the day.
 */
class DeliveryAssigned implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  Order  $order  the order as saved, with its new driver and day
     * @param  User  $driver  the driver it is now assigned to
     * @param  User|null  $previousDriver  the driver whose run it was on, if it was on one (not after a failed delivery, which ends that run's job)
     * @param  CarbonInterface|null  $previousDate  the day it was on that run
     */
    public function __construct(
        public Order $order,
        public User $driver,
        public ?User $previousDriver = null,
        public ?CarbonInterface $previousDate = null,
    ) {}
}
