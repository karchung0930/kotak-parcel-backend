<?php

namespace App\Events;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\DeliveryProgress;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A parcel's stop on its driver's run changed, or it left the run. Sent
 * over Reverb on the parcel's own two channels: the public one its tracking
 * page knows, and the private one of its customer's order page.
 *
 * The message carries the stop and the status, nothing else: no driver, no
 * location, no receiver and no other parcel. App\Listeners\SendDeliveryProgress
 * sends it from the queue, so it goes out straight away (ShouldBroadcastNow).
 * When Reverb refuses it or cannot be reached, BroadcastDeliveryProgress
 * reports the error, sends the other parcels theirs, and sends this one
 * again the next time the run is worked out.
 */
class DeliveryProgressUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * Create a new event instance.
     *
     * @param  array{position: int|null, stops_before: int|null, status: string}  $progress
     */
    public function __construct(
        public readonly string $publicChannel,
        public readonly string $privateChannel,
        public readonly array $progress,
    ) {}

    /**
     * Create the message for an order and its stop (null when it is not on the van).
     *
     * @param  array{position: int, stops_before: int}|null  $stop
     */
    public static function for(Order $order, ?array $stop): self
    {
        return new self(
            DeliveryProgress::publicChannel($order),
            DeliveryProgress::privateChannel($order),
            self::payload($order->status, $stop),
        );
    }

    /**
     * Get the message for a status and a stop.
     *
     * @param  array{position: int, stops_before: int}|null  $stop
     * @return array{position: int|null, stops_before: int|null, status: string}
     */
    public static function payload(OrderStatus $status, ?array $stop): array
    {
        return [
            'position' => $stop['position'] ?? null,
            'stops_before' => $stop['stops_before'] ?? null,
            'status' => $status->value,
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel($this->publicChannel),
            new PrivateChannel($this->privateChannel),
        ];
    }

    /**
     * Get the event's name on the channel.
     */
    public function broadcastAs(): string
    {
        return 'delivery.progress';
    }

    /**
     * Get the data to broadcast: only the stop and the status.
     *
     * @return array{position: int|null, stops_before: int|null, status: string}
     */
    public function broadcastWith(): array
    {
        return $this->progress;
    }
}
