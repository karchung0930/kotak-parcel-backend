<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * How far a parcel out for delivery is from its turn, as a count of stops:
 * "stop 3, 2 stops before yours". Never where the driver is, and nothing
 * about the other parcels or their receivers.
 *
 * The stops are the driver's list for the day as My jobs shows it
 * (Order::jobListFor()): today's list also carries the jobs left open on
 * earlier days, which come first until the driver moves them among today's
 * stops, so an overdue parcel left until last counts last. Only parcels on
 * the van count. One still to collect from a branch is not delivered
 * before it, and a delivered or failed one has left the list.
 */
class DeliveryProgress
{
    /**
     * Get the parcels on the van in the driver's run for the given day, in
     * delivery order.
     *
     * @return Collection<int, Order>
     */
    public function onTheVan(User $driver, CarbonInterface $day): Collection
    {
        return Order::query()
            ->jobListFor($driver, self::runDay($day))
            ->where('status', OrderStatus::PickedUp)
            ->get(['id', 'tracking_number', 'status', 'driver_id', 'scheduled_for']);
    }

    /**
     * Get the stop of each parcel on the van in the driver's run for the
     * given day, keyed by order id.
     *
     * @return array<int, array{position: int, stops_before: int}>
     */
    public function forRun(User $driver, CarbonInterface $day): array
    {
        return $this->onTheVan($driver, $day)
            ->values()
            ->mapWithKeys(fn (Order $order, int $index): array => [$order->id => self::stop($index)])
            ->all();
    }

    /**
     * Get the parcel's stop while it is out for delivery, or null.
     *
     * The driver is read on its own rather than loaded onto the order, so a
     * page that sends the order cannot send the driver with it by accident.
     *
     * @return array{position: int, stops_before: int}|null
     */
    public function forOrder(Order $order): ?array
    {
        if ($order->status !== OrderStatus::PickedUp || $order->scheduled_for === null) {
            return null;
        }

        $driver = User::query()->find($order->driver_id);

        return $driver === null ? null : ($this->forRun($driver, $order->scheduled_for)[$order->id] ?? null);
    }

    /**
     * Get the stop at a place in the list of parcels on the van (from 0).
     *
     * @return array{position: int, stops_before: int}
     */
    public static function stop(int $index): array
    {
        return ['position' => $index + 1, 'stops_before' => $index];
    }

    /**
     * Get the day of the list a delivery scheduled on the given day is on:
     * its own day, or today once that day has come, as today's list carries
     * the jobs left open on earlier days.
     */
    public static function runDay(CarbonInterface $scheduledFor): CarbonImmutable
    {
        $timezone = config()->string('kotak.timezone');
        $day = CarbonImmutable::parse($scheduledFor->toDateString(), $timezone);
        $today = CarbonImmutable::today($timezone);

        return $day->greaterThan($today) ? $day : $today;
    }

    /**
     * Get the public channel the parcel's progress is sent on, which the
     * tracking page listens to without signing in.
     *
     * The name is an HMAC-SHA256 of the tracking number with the app key, so
     * it cannot be worked out from a tracking number without the server's
     * secret, and Reverb never lists its channels to browsers. Only a page
     * the server gave it to, the parcel's tracking page, knows it.
     */
    public static function publicChannel(Order $order): string
    {
        return 'tracking.'.hash_hmac('sha256', "delivery-progress:{$order->tracking_number}", config()->string('app.key'));
    }

    /**
     * Get the private channel of the customer's own order page
     * (routes/channels.php).
     */
    public static function privateChannel(Order $order): string
    {
        return "orders.{$order->id}";
    }
}
