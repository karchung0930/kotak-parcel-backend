<?php

namespace App\Notifications;

use App\Enums\DeliveryFailureReason;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\MailDate;
use App\Support\MailText;
use App\Support\TrackingNumber;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

/**
 * Tells the receiver how their delivery is going, at the address the
 * customer gave for them: the delivery day once it is set or moved, out for
 * delivery, delivered, a failed attempt and the return to the sender.
 * Queued by App\Listeners\SendReceiverStatusNotification as an on-demand
 * notification, as the receiver has no account.
 *
 * It names the parcel and its sender and links to public tracking, and
 * nothing more: never the sender's phone number or address, nor the
 * customer's email, nor the receiver's own name, in case the customer
 * mistyped the address. The sender's name is the customer's own text, so it
 * stays out of the subject and is printed as a short plain name
 * (MailText::name()). Each email links to a page that stops them, and
 * carries the same link for mail apps' own unsubscribe button.
 */
#[Tries(3)]
#[Backoff(60)]
#[DeleteWhenMissingModels]
class ReceiverStatusUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * The facts are passed separately because the order may have moved on by
     * the time a queued email is sent.
     *
     * @param  OrderStatus  $status  the status the parcel reached
     * @param  OrderStatus|null  $from  the status before it: an assigned delivery moved to another day, or another try after a failed one
     * @param  CarbonInterface|null  $deliveryDate  the delivery day, for Assigned
     * @param  DeliveryFailureReason|null  $failureReason  why the attempt failed, for Delivery Failed
     * @param  bool  $lastAttempt  the failed attempt was the last one allowed, so the parcel goes back to the sender
     * @param  string|null  $receivedBy  who took the parcel, as the driver recorded it, for Delivered
     */
    public function __construct(
        public Order $order,
        public OrderStatus $status,
        public ?OrderStatus $from = null,
        public ?CarbonInterface $deliveryDate = null,
        public ?DeliveryFailureReason $failureReason = null,
        public bool $lastAttempt = false,
        public ?string $receivedBy = null,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(AnonymousNotifiable $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Make this the delivery-day email that counts for its order. One still
     * queued from before is then dropped when its turn comes, even when the
     * day was moved and moved back meanwhile, so the receiver only reads the
     * latest day.
     */
    public function markAsLatestDeliveryDay(): static
    {
        $this->id = (string) Str::uuid();

        // Only needed while emails are queued; after that the day check suffices.
        Cache::put(self::latestDeliveryDayKey($this->order), $this->id, now()->addMonth());

        return $this;
    }

    /**
     * Determine if the email should still go out when its turn comes.
     *
     * Not when receiver emails were switched off, or the receiver stopped
     * them, meanwhile. News of a step still under way is dropped once the
     * parcel has moved on, as that change sends its own email: a delivery day
     * after the delivery was moved, picked up or ended, or a newer
     * delivery-day email was queued; "out for delivery today" once the
     * attempt is over. Outcomes always go out.
     */
    public function shouldSend(AnonymousNotifiable $notifiable, string $channel): bool
    {
        if (! config()->boolean('kotak.receiver_emails') || ! $this->stillWanted($notifiable)) {
            return false;
        }

        return match ($this->status) {
            OrderStatus::Assigned => $this->order->status === OrderStatus::Assigned
                && $this->order->scheduled_for?->toDateString() === $this->deliveryDate?->toDateString()
                && in_array(Cache::get(self::latestDeliveryDayKey($this->order)), [null, $this->id], true),
            OrderStatus::PickedUp => $this->order->status === OrderStatus::PickedUp,
            default => true,
        };
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(AnonymousNotifiable $notifiable): MailMessage
    {
        $trackingNumber = $this->order->formatted_tracking_number;
        $sender = MailText::name($this->order->sender_name) ?: 'the sender';
        $stopUrl = $this->order->stopReceiverEmailsUrl();

        $mail = (new MailMessage)
            ->subject("Parcel {$trackingNumber}: {$this->headline()}")
            ->greeting('Hello,');

        foreach ($this->lines(TrackingNumber::formatForMail($this->order->tracking_number), $sender) as $line) {
            $mail->line($line);
        }

        return $mail
            ->action('Track your parcel', route('track', ['number' => $trackingNumber]))
            ->line('You are getting this email because the sender gave your email address for updates on this delivery.')
            // Our own link, so it is passed as Markdown that is never escaped.
            ->line(new HtmlString("If this parcel is not for you, or you no longer want these updates, you can [stop these emails]({$stopUrl})."))
            // The unsubscribe button of mail apps, in one click (RFC 8058).
            ->withSymfonyMessage(function (Email $message) use ($stopUrl): void {
                $message->getHeaders()
                    ->addTextHeader('List-Unsubscribe', "<{$stopUrl}>")
                    ->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
    }

    /**
     * Determine if the order still has this address for the receiver: they
     * may have stopped the emails since this one was queued.
     */
    private function stillWanted(AnonymousNotifiable $notifiable): bool
    {
        $address = $notifiable->routeNotificationFor('mail');

        return is_string($address)
            && $this->order->receiver_email !== null
            && strcasecmp($this->order->receiver_email, $address) === 0;
    }

    /**
     * Get the lines above the button: what happened, and what comes next.
     *
     * @param  string  $trackingNumber  as emails print it (TrackingNumber::formatForMail())
     * @return list<string>
     */
    private function lines(string $trackingNumber, string $sender): array
    {
        $parcel = "your parcel **{$trackingNumber}** from {$sender}";
        $beThere = 'Please make sure someone is at the address to receive it.';

        return match ($this->status) {
            OrderStatus::Assigned => match ($this->from) {
                // It may be the first day the receiver hears of, when an earlier one was dropped unsent.
                OrderStatus::Assigned => [
                    "The delivery day of {$parcel} has changed. It is now scheduled for **{$this->day()}**.",
                    $beThere,
                ],
                OrderStatus::DeliveryFailed => ["We will try again to deliver {$parcel} on **{$this->day()}**.", $beThere],
                default => [
                    "A parcel from {$sender} is on its way to you with Kotak, tracking number **{$trackingNumber}**.",
                    "It is scheduled for delivery on **{$this->day()}**.",
                    $beThere,
                ],
            },
            OrderStatus::PickedUp => [ucfirst($parcel).' is out for delivery today.', $beThere],
            OrderStatus::Delivered => [
                ucfirst($parcel).' has been delivered.',
                ...($this->receivedBy() !== '' ? ["It was received by {$this->receivedBy()}."] : []),
            ],
            OrderStatus::DeliveryFailed => [
                "We tried to deliver {$parcel}, but could not.",
                // "Other reason" tells the receiver nothing.
                ...($this->failureReason !== null && $this->failureReason !== DeliveryFailureReason::Other
                    ? ["Reason: **{$this->failureReason->label()}**."]
                    : []),
                // An admin may also return the parcel before the last attempt, so no new day is promised.
                $this->lastAttempt
                    ? 'That was the last delivery attempt, so the parcel will go back to the sender.'
                    : 'We will email you when the next delivery day is set, or if the parcel has to go back to the sender.',
            ],
            OrderStatus::ReturnedToSender => [
                ucfirst($parcel).' could not be delivered and has been returned to the sender.',
                "If you still need it, please get in touch with {$sender}.",
            ],
            default => [$this->status->description()],
        };
    }

    /**
     * Get the end of the subject, after the parcel.
     *
     * Subjects keep plain spaces in dates, so searching the inbox for a date still works.
     */
    private function headline(): string
    {
        $day = $this->deliveryDate?->format('l, j F');

        return match ($this->status) {
            OrderStatus::Assigned => match ($this->from) {
                OrderStatus::Assigned => "delivery moved to {$day}",
                OrderStatus::DeliveryFailed => "next delivery attempt on {$day}",
                default => "delivery on {$day}",
            },
            OrderStatus::PickedUp => 'out for delivery today',
            OrderStatus::Delivered => 'delivered',
            OrderStatus::DeliveryFailed => 'we could not deliver it',
            OrderStatus::ReturnedToSender => 'returned to the sender',
            default => $this->status->label(),
        };
    }

    /**
     * Get the delivery day as the body prints it, e.g. "Tuesday, 6 October 2026".
     */
    private function day(): string
    {
        return $this->deliveryDate !== null ? MailDate::long($this->deliveryDate) : '';
    }

    /**
     * Get who took the parcel as a short plain name, typed by the driver.
     */
    private function receivedBy(): string
    {
        return MailText::name($this->receivedBy);
    }

    /**
     * Get the cache key holding the id of the order's latest delivery-day email.
     */
    private static function latestDeliveryDayKey(Order $order): string
    {
        return "kotak.receiver-delivery-day.{$order->id}";
    }
}
