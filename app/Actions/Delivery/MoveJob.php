<?php

namespace App\Actions\Delivery;

use App\Enums\MoveDirection;
use App\Events\DeliveryRunReordered;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MoveJob
{
    /**
     * Move a stop on the driver's list for today one place up or down, and
     * number the whole list 1, 2, 3… again, so the places stay contiguous.
     *
     * The list is the one My jobs shows (Order::jobListFor()), with the jobs
     * carried over from earlier days. Every stop on it is placed on today's
     * run, so a carried-over job moves among today's stops like any other
     * and keeps its new place. Once committed, DeliveryRunReordered sends the
     * parcels on the van their new stops.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Order $order, User $driver, MoveDirection $direction): void
    {
        if (! $order->isAssignedTo($driver)) {
            throw new AuthorizationException('This delivery is not assigned to you.');
        }

        $today = today(config()->string('kotak.timezone'));

        if ($order->scheduled_for === null || ! $order->status->isActiveJob()) {
            throw ValidationException::withMessages(['direction' => 'This delivery is no longer on your list.']);
        }

        if ($order->scheduled_for->toDateString() > $today->toDateString()) {
            throw ValidationException::withMessages(['direction' => "You can only reorder today's stops."]);
        }

        DB::transaction(function () use ($order, $driver, $direction, $today): void {
            // The whole list is locked at once, in its order, so two moves on
            // it wait for each other instead of crossing.
            $stops = Order::query()->jobListFor($driver, $today)->lockForUpdate()->get()->values();
            $from = $stops->search(fn (Order $stop): bool => $stop->is($order));

            if ($from === false) {
                throw ValidationException::withMessages(['direction' => 'This delivery is no longer on your list.']);
            }

            $to = $from + $direction->offset();

            if (! $stops->has($to)) {
                throw ValidationException::withMessages(['direction' => $direction === MoveDirection::Up
                    ? 'This stop is already first.'
                    : 'This stop is already last.']);
            }

            $moved = $stops->all();
            [$moved[$from], $moved[$to]] = [$moved[$to], $moved[$from]];

            // A new place is not a change the customer sees, so updated_at stays.
            Order::withoutTimestamps(function () use ($moved, $today): void {
                foreach (array_values($moved) as $index => $stop) {
                    if ($stop->route_position !== $index + 1 || $stop->route_date?->toDateString() !== $today->toDateString()) {
                        $stop->forceFill(['route_position' => $index + 1, 'route_date' => $today->toDateString()])->save();
                    }
                }
            });

            DeliveryRunReordered::dispatch($driver, $today);
        }, 3);
    }
}
