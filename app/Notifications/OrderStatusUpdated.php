<?php

namespace App\Notifications;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Support\MailDate;
use App\Support\MailText;
use App\Support\TrackingNumber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderStatusUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * The status is passed separately because the order may have moved on
     * by the time a queued notification is sent. "Rescheduled" means an
     * assigned delivery was moved to another day.
     */
    public function __construct(
        public Order $order,
        public OrderStatus $status,
        public bool $rescheduled = false,
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
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $trackingNumber = $this->order->formatted_tracking_number;
        $bodyTrackingNumber = TrackingNumber::formatForMail($this->order->tracking_number);
        $headline = $this->rescheduled ? 'Delivery Rescheduled' : $this->status->label();

        $mail = (new MailMessage)
            ->subject("Parcel {$trackingNumber}: {$headline}")
            ->greeting('Hi '.MailText::plain($notifiable->name).',')
            ->line("Status update for parcel **{$bodyTrackingNumber}**: **{$headline}**")
            ->line($this->rescheduled ? 'Your delivery has been moved to a new date.' : $this->status->description());

        if ($this->status === OrderStatus::Assigned && $this->order->scheduled_for) {
            $mail->line(
                ($this->rescheduled ? 'New delivery date: ' : 'Scheduled delivery date: ')
                .MailDate::long($this->order->scheduled_for).'.',
            );
        }

        return $mail
            ->action('Track your parcel', route('track', ['number' => $trackingNumber]))
            ->line('Thank you for sending with Kotak.');
    }
}
