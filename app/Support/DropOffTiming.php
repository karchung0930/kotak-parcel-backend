<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\Order;
use Carbon\CarbonInterface;

/**
 * How long customers take to bring their parcels to a branch, so an admin
 * can set the unclaimed-order limit from real data.
 *
 * Days are Malaysian calendar days from the order day, the way the limit
 * counts them: an order placed on Monday and dropped off on Tuesday took
 * one day, whatever the hours. They are worked out in PHP from created_at
 * and dropped_off_at, so no database date functions are involved.
 */
class DropOffTiming
{
    /**
     * How far back the drop-offs are counted.
     */
    public const WINDOW_DAYS = 90;

    /**
     * Create a new instance.
     */
    public function __construct(
        private Settings $settings,
    ) {}

    /**
     * Summarise drop-offs in the last 90 days against the current limit,
     * with the orders still waiting now.
     *
     * Days have one decimal. The percentiles and the share are null when
     * nothing was dropped off in the window.
     *
     * @return array{
     *     window_days: int,
     *     limit_days: int,
     *     dropped_off: int,
     *     median_days: float|null,
     *     p90_days: float|null,
     *     p95_days: float|null,
     *     within_limit_percent: float|null,
     *     cancelled_unclaimed: int,
     *     waiting: int,
     *     expiring_tonight: int,
     *     suggestion: string|null,
     * }
     */
    public function summary(): array
    {
        $since = now()->subDays(self::WINDOW_DAYS);
        $limit = $this->settings->unclaimedOrderDays();
        $days = $this->daysToDropOff($since);
        $count = count($days);

        $p95 = self::percentile($days, 95);
        $withinLimit = count(array_filter($days, fn (float $day): bool => $day <= $limit));

        return [
            'window_days' => self::WINDOW_DAYS,
            'limit_days' => $limit,
            'dropped_off' => $count,
            'median_days' => self::oneDecimal(self::percentile($days, 50)),
            'p90_days' => self::oneDecimal(self::percentile($days, 90)),
            'p95_days' => self::oneDecimal($p95),
            'within_limit_percent' => $count > 0 ? round($withinLimit / $count * 100, 1) : null,
            'cancelled_unclaimed' => $this->cancelledUnclaimedSince($since),
            'waiting' => Order::query()->where('status', OrderStatus::Created)->count(),
            // The next run is at midnight, at the start of tomorrow in Malaysia.
            'expiring_tonight' => Order::query()->unclaimed(today(config()->string('kotak.timezone'))->addDay())->count(),
            'suggestion' => $p95 === null ? null : self::suggestion($p95, $limit),
        ];
    }

    /**
     * Get the p-th percentile (0 to 100) of values sorted from low to high,
     * interpolating between the two nearest ranks (like PERCENTILE.INC in a
     * spreadsheet). Null for no values.
     *
     * @param  list<float>  $sorted
     */
    public static function percentile(array $sorted, float $p): ?float
    {
        if ($sorted === []) {
            return null;
        }

        $rank = $p / 100 * (count($sorted) - 1);
        $lower = (int) floor($rank);
        $upper = (int) ceil($rank);

        return $sorted[$lower] + ($sorted[$upper] - $sorted[$lower]) * ($rank - $lower);
    }

    /**
     * Get the one-line advice for the page, e.g. "95% drop off within 2.4
     * days, inside the 7-day limit." The 95th percentile is rounded first,
     * so the advice agrees with the number it shows.
     */
    public static function suggestion(float $p95, int $limit): string
    {
        $p95 = round($p95, 1);
        $within = sprintf('95%% drop off within %s days', number_format($p95, 1));

        if ($p95 <= $limit) {
            return "{$within}, inside the {$limit}-day limit.";
        }

        $suggested = min((int) ceil($p95), Settings::LIMITS['unclaimed_order_days']['max']);

        return "{$within}, longer than the {$limit}-day limit. Consider allowing {$suggested} days.";
    }

    /**
     * Get the calendar days from ordering to drop-off for orders dropped off since the given moment, sorted.
     *
     * @return list<float>
     */
    private function daysToDropOff(CarbonInterface $since): array
    {
        $timezone = config()->string('kotak.timezone');
        $days = [];

        $orders = Order::query()
            ->whereNotNull('dropped_off_at')
            ->where('dropped_off_at', '>=', $since)
            ->select(['id', 'created_at', 'dropped_off_at'])
            ->lazyById(1000);

        foreach ($orders as $order) {
            if ($order->created_at === null || $order->dropped_off_at === null) {
                continue;
            }

            $ordered = $order->created_at->toImmutable()->setTimezone($timezone)->startOfDay();
            $droppedOff = $order->dropped_off_at->toImmutable()->setTimezone($timezone)->startOfDay();

            $days[] = max(0.0, round($ordered->diffInDays($droppedOff)));
        }

        sort($days);

        return $days;
    }

    /**
     * Count the orders the nightly job cancelled for never being dropped off.
     */
    private function cancelledUnclaimedSince(CarbonInterface $since): int
    {
        return Order::query()
            ->expiredUnclaimed()
            ->where('cancelled_at', '>=', $since)
            ->count();
    }

    /**
     * Round a number of days to one decimal.
     */
    private static function oneDecimal(?float $days): ?float
    {
        return $days === null ? null : round($days, 1);
    }
}
