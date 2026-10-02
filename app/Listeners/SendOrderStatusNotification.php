<?php

namespace App\Listeners;

use App\Events\OrderStatusChanged;
use App\Notifications\OrderStatusUpdated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Tries;

/**
 * Emails the customer each time their parcel changes status.
 */
#[Tries(3)]
#[Backoff(60)]
#[DeleteWhenMissingModels]
class SendOrderStatusNotification implements ShouldQueue
{
    /**
     * Determine whether the listener should be queued: only customers with a
     * verified email address are written to, and only about changes they
     * would notice.
     */
    public function shouldQueue(OrderStatusChanged $event): bool
    {
        return $event->order->customer->hasVerifiedEmail() && ! $this->isDriverSwap($event);
    }

    /**
     * Send the status update to the customer.
     */
    public function handle(OrderStatusChanged $event): void
    {
        // This listener already runs on the queue, so send now rather than queueing a second job.
        $event->order->customer->notifyNow(new OrderStatusUpdated(
            $event->order,
            $event->to,
            rescheduled: $event->from === $event->to,
        ));
    }

    /**
     * Determine if a delivery was only handed to another driver on the same
     * day, which changes nothing for the customer.
     *
     * Checked when the event fires, while the order still knows what its last save changed.
     */
    private function isDriverSwap(OrderStatusChanged $event): bool
    {
        return $event->from === $event->to && ! $event->order->wasChanged('scheduled_for');
    }
}
