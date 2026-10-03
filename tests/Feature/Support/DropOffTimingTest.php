<?php

namespace Tests\Feature\Support;

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\ExpireUnclaimedOrders;
use App\Models\Order;
use App\Support\DropOffTiming;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DropOffTimingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        // 10:00 in Kuala Lumpur.
        $this->travelTo(CarbonImmutable::parse('2026-10-03 02:00:00', 'UTC'));
    }

    public function test_it_summarises_drop_offs_in_the_last_90_days()
    {
        // Days from ordering to drop-off: 1, 2, 3, 4 and 10.
        foreach ([1, 2, 3, 4, 10] as $i => $days) {
            $this->droppedOffAfter($days, droppedOffDaysAgo: $i + 1);
        }

        // Dropped off before the window: not counted.
        $this->droppedOffAfter(30, droppedOffDaysAgo: 91);

        $summary = app(DropOffTiming::class)->summary();

        $this->assertSame(90, $summary['window_days']);
        $this->assertSame(7, $summary['limit_days']);
        $this->assertSame(5, $summary['dropped_off']);
        $this->assertSame(3.0, $summary['median_days']);
        $this->assertSame(7.6, $summary['p90_days']);
        $this->assertSame(8.8, $summary['p95_days']);
        $this->assertSame(80.0, $summary['within_limit_percent']);
        $this->assertSame('95% drop off within 8.8 days, longer than the 7-day limit. Consider allowing 9 days.', $summary['suggestion']);
    }

    public function test_days_are_malaysian_calendar_days_from_the_order_day()
    {
        // Ordered at 23:30 and dropped off an hour later, the next day: 1 day.
        $this->droppedOffBetween('2026-10-01 23:30:00', '2026-10-02 00:30:00');
        // Ordered just after midnight and dropped off before the next one: the same day.
        $this->droppedOffBetween('2026-10-01 00:10:00', '2026-10-01 23:50:00');
        $this->droppedOffBetween('2026-10-01 09:00:00', '2026-10-01 18:00:00');

        $summary = app(DropOffTiming::class)->summary();

        $this->assertSame(0.0, $summary['median_days']);
        $this->assertSame(0.8, $summary['p90_days']);
    }

    public function test_a_drop_off_on_the_deadline_day_is_within_the_limit()
    {
        // Ordered 1 September, dropped off on 8 September in the evening: on time.
        $this->droppedOffBetween('2026-09-01 10:00:00', '2026-09-08 20:00:00');

        $summary = app(DropOffTiming::class)->summary();

        $this->assertSame(7.0, $summary['p95_days']);
        $this->assertSame(100.0, $summary['within_limit_percent']);
        $this->assertSame('95% drop off within 7.0 days, inside the 7-day limit.', $summary['suggestion']);
    }

    public function test_a_drop_off_the_day_after_the_deadline_day_is_outside_the_limit()
    {
        // Possible when the counter takes a parcel the nightly job has not cancelled yet.
        $this->droppedOffBetween('2026-09-01 10:00:00', '2026-09-09 08:00:00');

        $summary = app(DropOffTiming::class)->summary();

        $this->assertSame(0.0, $summary['within_limit_percent']);
        $this->assertSame('95% drop off within 8.0 days, longer than the 7-day limit. Consider allowing 8 days.', $summary['suggestion']);
    }

    public function test_it_counts_orders_cancelled_for_never_being_dropped_off()
    {
        Order::factory()->create(['created_at' => now()->subDays(8)]);
        app(ExpireUnclaimedOrders::class)->handle();

        // Cancelled by the customer, or before the window: not counted.
        $byCustomer = Order::factory()->create();
        app(CancelOrder::class)->handle($byCustomer, $byCustomer->customer);
        $old = Order::factory()->create(['created_at' => now()->subDays(120)]);
        app(ExpireUnclaimedOrders::class)->handle();
        $old->forceFill(['cancelled_at' => now()->subDays(100)])->save();

        $this->assertSame(1, app(DropOffTiming::class)->summary()['cancelled_unclaimed']);
    }

    public function test_it_counts_the_orders_waiting_now_and_those_the_next_nightly_run_cancels()
    {
        // Tonight's run, at midnight starting 4 October, cancels orders whose
        // deadline is today (3 October) or earlier: with 7 days to drop off,
        // those placed on 26 September or before, whatever the hour.
        $this->placedAt('2026-09-20 15:00:00');
        $this->placedAt('2026-09-26 00:00:10');
        $this->placedAt('2026-09-26 23:59:00');
        $this->placedAt('2026-09-27 00:00:10');
        Order::factory()->create();
        Order::factory()->droppedOff()->create(['created_at' => now()->subDays(20)]);

        $summary = app(DropOffTiming::class)->summary();

        $this->assertSame(5, $summary['waiting']);
        $this->assertSame(3, $summary['expiring_tonight']);
    }

    public function test_without_drop_offs_there_are_no_statistics()
    {
        Order::factory()->create();

        $summary = app(DropOffTiming::class)->summary();

        $this->assertSame(0, $summary['dropped_off']);
        $this->assertNull($summary['median_days']);
        $this->assertNull($summary['p90_days']);
        $this->assertNull($summary['p95_days']);
        $this->assertNull($summary['within_limit_percent']);
        $this->assertNull($summary['suggestion']);
        $this->assertSame(1, $summary['waiting']);
    }

    /**
     * Create an order placed at the given Kuala Lumpur time, still waiting for drop-off.
     */
    private function placedAt(string $localTime): void
    {
        Order::factory()->create(['created_at' => CarbonImmutable::parse($localTime, 'Asia/Kuala_Lumpur')->utc()]);
    }

    /**
     * Create an order placed and dropped off at the given Kuala Lumpur times.
     */
    private function droppedOffBetween(string $placed, string $droppedOff): void
    {
        Order::factory()->droppedOff()->create()->forceFill([
            'created_at' => CarbonImmutable::parse($placed, 'Asia/Kuala_Lumpur')->utc(),
            'dropped_off_at' => CarbonImmutable::parse($droppedOff, 'Asia/Kuala_Lumpur')->utc(),
        ])->save();
    }

    /**
     * Create an order dropped off the given number of days after it was placed.
     */
    private function droppedOffAfter(int $days, int $droppedOffDaysAgo): void
    {
        $droppedOffAt = now()->subDays($droppedOffDaysAgo);

        Order::factory()->droppedOff()->create()->forceFill([
            'created_at' => $droppedOffAt->subDays($days),
            'dropped_off_at' => $droppedOffAt,
        ])->save();
    }
}
