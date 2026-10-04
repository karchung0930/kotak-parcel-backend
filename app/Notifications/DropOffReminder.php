<?php

namespace App\Notifications;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Support\MailDate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Tries;

/**
 * Reminds a customer to drop their parcel off before the order is cancelled
 * automatically. Sent once per order by App\Actions\Orders\SendDropOffReminders.
 */
#[Tries(3)]
#[Backoff(60)]
#[DeleteWhenMissingModels]
class DropOffReminder extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Order $order,
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
     * Determine if the reminder should still go out: not once the parcel
     * has been dropped off or the order cancelled while it was queued, nor
     * to an account deactivated meanwhile (its link needs a sign-in).
     */
    public function shouldSend(User $notifiable, string $channel): bool
    {
        return $notifiable->is_active && $this->order->status === OrderStatus::Created;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $trackingNumber = $this->order->formatted_tracking_number;
        $deadline = $this->order->dropOffDeadline();
        $branch = $this->order->branch;

        return (new MailMessage)
            ->subject("Parcel {$trackingNumber}: drop it off by {$deadline?->format('j F')}")
            ->greeting("Hi {$notifiable->name},")
            ->line("Your parcel **{$trackingNumber}** to {$this->order->receiver_name} is still waiting to be dropped off.")
            ->line('Drop-off deadline: **'.($deadline !== null ? MailDate::long($deadline) : '').'**. If it is not dropped off by then, the order is cancelled automatically.')
            ->line("Your drop-off branch: **{$branch->name}**, {$branch->mailAddress()}.")
            ->line("Opening hours: {$branch->opening_hours}.")
            ->action('View your order', route('orders.show', $this->order))
            ->line('No longer sending it? You can cancel the order on the same page.')
            ->line('Thank you for sending with Kotak.');
    }
}
