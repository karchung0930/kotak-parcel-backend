<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum OrderStatus: string
{
    use HasOptions;

    case Created = 'created';
    case DroppedOff = 'dropped_off';
    case Paid = 'paid';
    case Assigned = 'assigned';
    case PickedUp = 'picked_up';
    case Delivered = 'delivered';
    case DeliveryFailed = 'delivery_failed';
    case ReturnedToSender = 'returned_to_sender';
    case Cancelled = 'cancelled';

    /**
     * Get the human readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::DroppedOff => 'Dropped Off',
            self::Paid => 'Paid',
            self::Assigned => 'Assigned',
            self::PickedUp => 'Picked Up',
            self::Delivered => 'Delivered',
            self::DeliveryFailed => 'Delivery Failed',
            self::ReturnedToSender => 'Returned to Sender',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Get a plain sentence explaining the status to the customer.
     */
    public function description(): string
    {
        return match ($this) {
            self::Created => 'Your order is ready to be dropped off at your chosen Kotak branch.',
            self::DroppedOff => 'Your parcel has been received and weighed at the branch.',
            self::Paid => 'Your parcel is paid for and waiting to be scheduled for delivery.',
            self::Assigned => 'Your parcel has been scheduled for delivery with one of our drivers.',
            self::PickedUp => 'Your parcel is out for delivery.',
            self::Delivered => 'Your parcel has been delivered.',
            self::DeliveryFailed => 'We could not deliver your parcel, and our team will arrange the next step.',
            self::ReturnedToSender => 'Your parcel has been returned to the sender.',
            self::Cancelled => 'This order has been cancelled.',
        };
    }

    /**
     * Get the statuses this status may move to next.
     *
     * Assigned may move to Assigned again: an admin reassigns the delivery to
     * another driver or day until the driver collects the parcel.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Created => [self::DroppedOff, self::Cancelled],
            self::DroppedOff => [self::Paid, self::Cancelled],
            self::Paid => [self::Assigned],
            self::Assigned => [self::Assigned, self::PickedUp],
            self::PickedUp => [self::Delivered, self::DeliveryFailed],
            self::DeliveryFailed => [self::Assigned, self::ReturnedToSender],
            self::Delivered, self::ReturnedToSender, self::Cancelled => [],
        };
    }

    /**
     * Get the statuses of a delivery its driver still has to complete.
     *
     * @return list<self>
     */
    public static function activeJobs(): array
    {
        return [self::Assigned, self::PickedUp];
    }

    /**
     * Determine if the order is a delivery its driver still has to complete.
     */
    public function isActiveJob(): bool
    {
        return in_array($this, self::activeJobs(), true);
    }

    /**
     * Determine if the status may move to the given status.
     */
    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedNext(), true);
    }

    /**
     * Determine if the order's journey has ended.
     */
    public function isFinal(): bool
    {
        return $this->allowedNext() === [];
    }
}
