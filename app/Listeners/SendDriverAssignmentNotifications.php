<?php

namespace App\Listeners;

use App\Events\DeliveryAssigned;
use App\Notifications\DriverJobAssigned;
use App\Notifications\DriverJobRemoved;

/**
 * Emails the drivers whose run a dispatch decision changed: the assigned
 * driver about a new or moved delivery, and the driver it was taken from.
 *
 * It runs straight after the commit and only queues the emails, one per
 * driver, so each is sent and retried on its own.
 */
class SendDriverAssignmentNotifications
{
    /**
     * Queue an email for each driver whose run changed.
     *
     * When the delivery stays with its driver on another day, that driver
     * hears about the new day. Nobody hears anything when neither the driver
     * nor the day changed.
     */
    public function handle(DeliveryAssigned $event): void
    {
        $order = $event->order;
        $date = $order->scheduled_for;
        $previous = $event->previousDriver;
        $previousDate = $event->previousDate;

        if ($date === null) {
            return;
        }

        $sameDriver = $previous !== null && $previous->is($event->driver);
        $sameDay = $previousDate?->toDateString() === $date->toDateString();

        if (! ($sameDriver && $sameDay) && $event->driver->canBeEmailed()) {
            $event->driver->notify(new DriverJobAssigned($order, $date, $sameDriver ? $previousDate : null));
        }

        if ($previous !== null && $previousDate !== null && ! $sameDriver && $previous->canBeEmailed()) {
            $previous->notify(new DriverJobRemoved($order, $previousDate));
        }
    }
}
