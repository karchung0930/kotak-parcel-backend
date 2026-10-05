<?php

namespace App\Listeners;

use App\Actions\Delivery\BroadcastDeliveryProgress;
use App\Enums\OrderStatus;
use App\Events\DeliveryAssigned;
use App\Events\DeliveryRunReordered;
use App\Events\OrderStatusChanged;
use App\Support\DeliveryProgress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Tries;

/**
 * Sends the parcels on a driver's run their new stop numbers over Reverb
 * whenever the run changes: a parcel is picked up, delivered or not
 * delivered, a delivery is assigned, moved or handed to another driver, or
 * the driver puts the stops in another order.
 *
 * It runs on the queue, so neither a slow Reverb nor many parcels on the
 * van ever slow down the driver's or the admin's screens. It has a queue of
 * its own, "live", which the worker takes before the emails and rate
 * imports on "default" (queue:work --queue=live,default), so a new count
 * never waits behind a batch of emails.
 */
#[Queue('live')]
#[Tries(3)]
#[Backoff(5)]
#[DeleteWhenMissingModels]
class SendDeliveryProgress implements ShouldQueue
{
    /**
     * The statuses that put a parcel on the van or take it off.
     *
     * @var list<OrderStatus>
     */
    private const STATUSES = [
        OrderStatus::PickedUp,
        OrderStatus::Delivered,
        OrderStatus::DeliveryFailed,
    ];

    /**
     * Create the event listener.
     */
    public function __construct(
        private BroadcastDeliveryProgress $broadcast,
    ) {}

    /**
     * Determine whether the listener should be queued: assignments
     * (DeliveryAssigned) are handled with their previous run, not as a
     * status change.
     */
    public function shouldQueue(OrderStatusChanged|DeliveryAssigned|DeliveryRunReordered $event): bool
    {
        return ! $event instanceof OrderStatusChanged || in_array($event->to, self::STATUSES, true);
    }

    /**
     * Send the new numbers of each run the change touched.
     */
    public function handle(OrderStatusChanged|DeliveryAssigned|DeliveryRunReordered $event): void
    {
        if ($event instanceof DeliveryRunReordered) {
            $this->broadcast->handle($event->driver, $event->date);

            return;
        }

        $order = $event->order;

        if ($order->driver === null || $order->scheduled_for === null) {
            return;
        }

        $this->broadcast->handle($order->driver, $order->scheduled_for, $order);

        // A delivery handed to another driver or moved to another day also
        // leaves the run it was on.
        if ($event instanceof DeliveryAssigned
            && $event->previousDriver !== null
            && $event->previousDate !== null
            && ! ($event->previousDriver->is($order->driver)
                && DeliveryProgress::runDay($event->previousDate)->equalTo(DeliveryProgress::runDay($order->scheduled_for)))) {
            $this->broadcast->handle($event->previousDriver, $event->previousDate);
        }
    }
}
