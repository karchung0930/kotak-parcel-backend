<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only place that writes an order's status.
 *
 * Every change is validated against OrderStatus::allowedNext(), recorded in
 * the append-only history, and announced with an OrderStatusChanged event
 * that is dispatched only after the database transaction commits.
 */
class OrderStatusService
{
    /**
     * Record the first history entry for a newly created order.
     *
     * Call it in the transaction that saves the order (as CreateOrder does).
     */
    public function recordCreation(Order $order, ?User $actor): void
    {
        $this->record($order, null, OrderStatus::Created, $actor, null);
    }

    /**
     * Move the order to a new status under a row lock and return the updated order.
     *
     * Actions that check extra rules first (ownership, amounts, attempt limits)
     * already hold the lock in their own transaction. Locking again here is
     * then cheap, and it keeps this method safe for callers that do not.
     *
     * @param  array<string, mixed>  $attributes  trusted values computed by an action (never raw request input)
     *
     * @throws InvalidStatusTransition
     */
    public function transition(Order $order, OrderStatus $to, ?User $actor, ?string $note = null, array $attributes = []): Order
    {
        return DB::transaction(function () use ($order, $to, $actor, $note, $attributes): Order {
            // Re-read under lock so concurrent requests cannot both pass the check.
            $order = $order->freshLocked();
            $from = $order->status;

            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransition::between($from, $to);
            }

            $timestamp = $this->timestampColumn($to);

            $order->forceFill([
                ...$attributes,
                'status' => $to,
                ...($timestamp ? [$timestamp => now()] : []),
            ])->save();

            $this->record($order, $from, $to, $actor, $note);

            return $order;
        });
    }

    /**
     * Append a history row and announce the change once the transaction commits.
     */
    private function record(Order $order, ?OrderStatus $from, OrderStatus $to, ?User $actor, ?string $note): void
    {
        $order->statusEvents()->create([
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'actor_id' => $actor?->id,
            'branch_id' => $actor?->branch_id,
        ]);

        OrderStatusChanged::dispatch($order, $from, $to);
    }

    /**
     * Get the order column that records when the status was reached, if any.
     */
    private function timestampColumn(OrderStatus $status): ?string
    {
        return match ($status) {
            OrderStatus::DroppedOff => 'dropped_off_at',
            OrderStatus::Paid => 'paid_at',
            OrderStatus::Delivered => 'delivered_at',
            OrderStatus::Cancelled => 'cancelled_at',
            default => null,
        };
    }
}
