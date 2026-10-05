<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\User;
use App\Support\MailDate;
use App\Support\MailText;
use App\Support\TrackingNumber;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Tries;

/**
 * Tells a driver that a delivery was taken off their round for a day and
 * given to another driver, so they do not collect it. Queued by
 * App\Listeners\SendDriverAssignmentNotifications.
 */
#[Tries(3)]
#[Backoff(60)]
#[DeleteWhenMissingModels]
class DriverJobRemoved extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * The day is the one the delivery was on this driver's round for.
     */
    public function __construct(
        public Order $order,
        public CarbonInterface $date,
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
     * Determine if the email should still go out: not if the delivery was
     * handed back to this driver for that day while it was queued, nor if
     * their email about the job was dropped and they never heard of it, nor
     * to an account that can no longer be emailed.
     */
    public function shouldSend(User $notifiable, string $channel): bool
    {
        $backOnTheRound = $this->order->isAssignedTo($notifiable)
            && $this->order->status->isActiveJob()
            && $this->order->scheduled_for?->toDateString() === $this->date->toDateString();

        return $notifiable->canBeEmailed()
            && ! $backOnTheRound
            && ! DriverJobAssigned::wasUnheardBy($this->order, $notifiable);
    }

    /**
     * Get the mail representation of the notification.
     *
     * A day already past was an overdue job, which the driver saw carried
     * over on today's My jobs, so the email speaks of their list instead.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $trackingNumber = $this->order->formatted_tracking_number;
        $bodyTrackingNumber = TrackingNumber::formatForMail($this->order->tracking_number);
        $date = $this->date->toDateString();
        $today = today(config()->string('kotak.timezone'))->toDateString();

        $mail = (new MailMessage)->greeting('Hi '.MailText::plain($notifiable->name).',');

        // No-break spaces keep each sentence's last words together on narrow screens.
        if ($date < $today) {
            $mail->subject("Delivery {$trackingNumber} removed from your list")
                ->line("Delivery **{$bodyTrackingNumber}**, carried over from ".MailDate::long($this->date).", has been removed from your list. You no longer need to collect\u{00A0}it.");
        } else {
            $mail->subject("Delivery {$trackingNumber} removed from your round for {$this->date->format('l, j F')}")
                ->line("Delivery **{$bodyTrackingNumber}** has been removed from your round for **".MailDate::long($this->date)."**. You no longer need to collect\u{00A0}it.");
        }

        // My jobs opens on today, which also lists overdue jobs; a later day needs its date.
        return $mail
            ->line('It was to be collected from '.MailText::plain($this->order->branch->name)." for delivery to {$this->order->deliveryArea()}.")
            ->action('Open My jobs', route('driver.jobs', $date > $today ? ['date' => $date] : []));
    }
}
