<?php

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Notifications\ReceiverStatusUpdated;
use App\Support\Settings;
use Illuminate\Support\Facades\Notification;

/**
 * Emails the receiver about their delivery, when the customer gave an email
 * address for them: once a delivery day is set or moved, when the parcel is
 * out for delivery, delivered or could not be delivered, and when it goes
 * back to the sender.
 *
 * Nothing is sent before a delivery day is set, so an address is only
 * written to once its parcel has been dropped off, paid for and dispatched.
 * RECEIVER_EMAILS=false (config kotak.receiver_emails) stops them all.
 *
 * It runs straight after the commit and only queues the email, which is sent
 * and retried on its own.
 */
class SendReceiverStatusNotification
{
    /**
     * The statuses the receiver hears about.
     *
     * @var list<OrderStatus>
     */
    private const STATUSES = [
        OrderStatus::Assigned,
        OrderStatus::PickedUp,
        OrderStatus::Delivered,
        OrderStatus::DeliveryFailed,
        OrderStatus::ReturnedToSender,
    ];

    /**
     * Create the event listener.
     */
    public function __construct(
        private Settings $settings,
    ) {}

    /**
     * Queue the update for the receiver, if they would notice the change.
     */
    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order;

        if (! $this->shouldEmail($event)) {
            return;
        }

        $attempt = in_array($event->to, [OrderStatus::Delivered, OrderStatus::DeliveryFailed], true)
            ? $order->latestAttempt()->first()
            : null;
        $failed = $event->to === OrderStatus::DeliveryFailed;

        $notification = new ReceiverStatusUpdated(
            $order,
            $event->to,
            $event->from,
            deliveryDate: $event->to === OrderStatus::Assigned ? $order->scheduled_for : null,
            failureReason: $failed ? $attempt?->failure_reason : null,
            lastAttempt: $failed && $order->failedAttemptsCount() >= $this->settings->maxFailedAttempts(),
            receivedBy: $event->to === OrderStatus::Delivered ? $attempt?->recipient_name : null,
        );

        if ($event->to === OrderStatus::Assigned) {
            $notification->markAsLatestDeliveryDay();
        }

        Notification::route('mail', $order->receiver_email)->notify($notification);
    }

    /**
     * Determine if the receiver should hear about the change: receiver
     * emails are on, the receiver has an address, it is a step they would
     * notice (not a same-day driver swap, as for the customer), and it is not
     * the customer's own address, which already gets the customer's email
     * about it.
     */
    private function shouldEmail(OrderStatusChanged $event): bool
    {
        $order = $event->order;

        return config()->boolean('kotak.receiver_emails')
            && filled($order->receiver_email)
            && in_array($event->to, self::STATUSES, true)
            && ! $event->isDriverSwap()
            && ! $this->customerIsEmailedAtTheSameAddress($order);
    }

    /**
     * Determine if the receiver's address is the customer's own, which
     * App\Listeners\SendOrderStatusNotification already writes to.
     */
    private function customerIsEmailedAtTheSameAddress(Order $order): bool
    {
        return strcasecmp((string) $order->receiver_email, $order->customer->email) === 0
            && $order->customer->hasVerifiedEmail();
    }
}
