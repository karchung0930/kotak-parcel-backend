<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\User;
use App\Support\MailDate;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Cache;

/**
 * Tells a driver about a delivery put on their round: a new job (a first
 * assignment, a reschedule after a failed attempt, or one handed over from
 * another driver) or one of theirs moved to another day. Queued by
 * App\Listeners\SendDriverAssignmentNotifications.
 *
 * It gives the delivery area, never the address or the receiver: those are
 * on the job page, behind the driver's sign-in.
 */
#[Tries(3)]
#[Backoff(60)]
#[DeleteWhenMissingModels]
class DriverJobAssigned extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * The day is passed separately because the order may have moved on by
     * the time a queued email is sent. "Moved from" is the delivery's
     * previous day when it stays with the same driver.
     */
    public function __construct(
        public Order $order,
        public CarbonInterface $scheduledFor,
        public ?CarbonInterface $movedFrom = null,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Determine if the email should still go out: not once the delivery was
     * given to another driver or day while it was queued (that change sends
     * its own email), nor to an account that can no longer be emailed
     * (deactivated, or an address changed and not confirmed yet).
     *
     * A new job dropped here is one the driver never heard of, so it is
     * noted for DriverJobRemoved: telling them it left their round would
     * only confuse them. A dropped move is not, as they knew the job on its
     * earlier day.
     */
    public function shouldSend(User $notifiable, string $channel): bool
    {
        $send = $notifiable->canBeEmailed()
            && $this->order->isAssignedTo($notifiable)
            && $this->order->status->isActiveJob()
            && $this->order->scheduled_for?->toDateString() === $this->scheduledFor->toDateString();

        if ($send) {
            Cache::forget(self::unheardKey($this->order, $notifiable));
        } elseif ($this->movedFrom === null) {
            // Only needed until the handover's own emails have gone out.
            Cache::put(self::unheardKey($this->order, $notifiable), true, now()->addDay());
        }

        return $send;
    }

    /**
     * Determine if the driver's last email about the delivery was a new job
     * that was dropped unsent, so they never heard it was on their round.
     */
    public static function wasUnheardBy(Order $order, User $driver): bool
    {
        return Cache::has(self::unheardKey($order, $driver));
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $trackingNumber = $this->order->formatted_tracking_number;
        $branch = $this->order->branch;
        $day = MailDate::long($this->scheduledFor);

        return (new MailMessage)
            ->subject($this->movedFrom !== null
                ? "Delivery {$trackingNumber} moved to {$this->scheduledFor->format('l, j F')}"
                : "New delivery for {$this->scheduledFor->format('l, j F')}: {$trackingNumber}")
            ->greeting("Hi {$notifiable->name},")
            ->line($this->movedFrom !== null
                ? 'A delivery on your round has moved from '.MailDate::long($this->movedFrom)." to **{$day}**."
                : "A delivery has been added to your round for **{$day}**.")
            ->line("Tracking number: **{$trackingNumber}**")
            ->line("Collect it from: **{$branch->name}**, {$branch->mailAddress()}.")
            ->line("Delivery area: **{$this->order->deliveryArea()}**")
            ->action('Open the job', route('driver.jobs.show', $this->order))
            ->line("The receiver's name, address and phone number are on the job page.");
    }

    /**
     * Get the cache key noting that the driver never heard of the delivery.
     */
    private static function unheardKey(Order $order, User $driver): string
    {
        return "kotak.driver-job-unheard.{$order->id}.{$driver->id}";
    }
}
