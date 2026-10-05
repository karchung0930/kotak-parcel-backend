<?php

namespace Tests\Feature\Models;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderQueriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_are_found_by_tracking_number_in_any_format()
    {
        $order = Order::factory()->create(['tracking_number' => 'KT7Q4M92XD']);

        foreach (['KT7Q4M92XD', 'kt-7q4m92xd', ' KT-7Q4M92XD '] as $input) {
            $this->assertTrue($order->is(Order::byTrackingNumber($input)->first()), $input);
        }

        $this->assertNull(Order::byTrackingNumber('KT-00000000')->first());
        $this->assertNull(Order::byTrackingNumber('nonsense')->first());
        $this->assertNull(Order::byTrackingNumber(null)->first());
    }

    public function test_dispatch_queues()
    {
        $paid = Order::factory()->paid()->create();
        $failed = Order::factory()->deliveryFailed()->create();
        Order::factory()->create();

        $this->assertSame([$paid->id], Order::awaitingDispatch()->pluck('id')->all());
        $this->assertSame([$failed->id], Order::failedAwaitingAction()->pluck('id')->all());
    }

    public function test_a_drivers_jobs_for_a_day()
    {
        $driver = User::factory()->driver()->create();
        $today = Order::factory()->assigned($driver)->create();
        $pickedUp = Order::factory()->pickedUp($driver)->create();
        Order::factory()->delivered($driver)->create();
        Order::factory()->assigned($driver)->create(['scheduled_for' => today(config('kotak.timezone'))->addDay()]);
        Order::factory()->assigned()->create();

        $jobs = Order::forDriver($driver)->activeJobs()->scheduledOn(today(config('kotak.timezone')))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$today->id, $pickedUp->id], $jobs);

        $driverWithCount = User::withJobsCountOn(today(config('kotak.timezone')))->findOrFail($driver->id);
        $this->assertSame(2, $driverWithCount->getAttribute('jobs_count'));
    }

    public function test_open_deliveries_past_their_day_are_overdue()
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-30 10:00', 'Asia/Kuala_Lumpur'));
        $driver = User::factory()->driver()->create();
        $overdue = Order::factory()->pickedUp($driver)->create(['scheduled_for' => '2026-09-29']);
        $today = Order::factory()->assigned($driver)->create(['scheduled_for' => '2026-09-30']);
        $finished = Order::factory()->delivered($driver)->create(['scheduled_for' => '2026-09-29']);

        $this->assertTrue($overdue->isOverdue());
        $this->assertFalse($today->isOverdue());
        $this->assertFalse($finished->isOverdue());
        $this->assertFalse(Order::factory()->paid()->create()->isOverdue());

        $this->assertSame(
            [$overdue->id],
            Order::activeJobs()->scheduledBefore(CarbonImmutable::parse('2026-09-30'))->pluck('id')->all(),
        );
    }

    public function test_todays_job_list_carries_over_open_jobs_whatever_timezone_the_day_is_in()
    {
        // Half past midnight in Kuala Lumpur, still the day before in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 00:30', 'Asia/Kuala_Lumpur'));
        $driver = User::factory()->driver()->create();
        $overdue = Order::factory()->assigned($driver)->create(['scheduled_for' => '2026-10-02']);
        $today = Order::factory()->assigned($driver)->create(['scheduled_for' => '2026-10-05']);
        Order::factory()->assigned($driver)->create(['scheduled_for' => '2026-10-06']);

        foreach ([CarbonImmutable::parse('2026-10-05', 'Asia/Kuala_Lumpur'), CarbonImmutable::parse('2026-10-05', 'UTC')] as $day) {
            $this->assertSame([$overdue->id, $today->id], Order::jobListFor($driver, $day)->pluck('id')->all(), $day->toIso8601String());
        }

        // Another day lists only its own jobs.
        $this->assertSame([], Order::jobListFor($driver, CarbonImmutable::parse('2026-10-04', 'UTC'))->pluck('id')->all());
    }

    public function test_todays_job_list_starts_with_the_jobs_not_yet_on_todays_run_then_follows_the_drivers_order()
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Kuala_Lumpur'));
        $driver = User::factory()->driver()->create();
        $job = fn (string $scheduledFor, int $position, ?string $routeDate = null, string $postcode = '50000', bool $pickedUp = false): Order => ($pickedUp ? Order::factory()->pickedUp($driver) : Order::factory()->assigned($driver))->create([
            'scheduled_for' => $scheduledFor,
            'route_position' => $position,
            'route_date' => $routeDate ?? $scheduledFor,
            'postcode' => $postcode,
        ]);

        // Today's run in the driver's order, with a job from Saturday the
        // driver put second.
        $third = $job('2026-10-05', 3, pickedUp: true);
        $first = $job('2026-10-05', 1);
        $placed = $job('2026-10-03', 2, '2026-10-05', pickedUp: true);
        // Not yet on today's run: by the run each was last on, then its
        // place, then its postcode. On Sunday the driver put one of
        // Saturday's jobs last, so it is still behind Sunday's own stop.
        $sundayLast = $job('2026-10-03', 5, '2026-10-04');
        $sunday = $job('2026-10-04', 1, pickedUp: true);
        $saturdayFar = $job('2026-10-03', 2, postcode: '60000');
        $saturdayNear = $job('2026-10-03', 2, postcode: '40000', pickedUp: true);
        $saturday = $job('2026-10-03', 1);
        $tomorrow = $job('2026-10-06', 1);

        $today = CarbonImmutable::parse('2026-10-05', 'Asia/Kuala_Lumpur');
        $this->assertSame(
            [$saturday->id, $saturdayNear->id, $saturdayFar->id, $sunday->id, $sundayLast->id, $first->id, $placed->id, $third->id],
            Order::jobListFor($driver, $today)->pluck('id')->all(),
        );
        // A later day lists its own run.
        $this->assertSame([$tomorrow->id], Order::jobListFor($driver, $today->addDay())->pluck('id')->all());
        // An earlier day lists its jobs still open, which are on today's
        // list now, in the same order.
        $this->assertSame(
            [$saturday->id, $saturdayNear->id, $saturdayFar->id, $sundayLast->id, $placed->id],
            Order::jobListFor($driver, $today->subDays(2))->pluck('id')->all(),
        );
    }

    public function test_a_job_carried_over_keeps_its_place_in_the_drivers_order_overnight()
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04 15:00', 'Asia/Kuala_Lumpur'));
        $driver = User::factory()->driver()->create();
        $sunday = CarbonImmutable::parse('2026-10-04', 'Asia/Kuala_Lumpur');
        // On Sunday the driver left Saturday's parcel until last: a move
        // places the whole list on Sunday's run (MoveJob).
        $leftOver = Order::factory()->pickedUp($driver)->create(['scheduled_for' => '2026-10-03', 'route_position' => 3, 'route_date' => '2026-10-04', 'postcode' => '10000']);
        $first = Order::factory()->pickedUp($driver)->create(['scheduled_for' => '2026-10-04', 'route_position' => 1, 'postcode' => '60000']);
        $second = Order::factory()->assigned($driver)->create(['scheduled_for' => '2026-10-04', 'route_position' => 2, 'postcode' => '50000']);
        $this->assertSame([$first->id, $second->id, $leftOver->id], Order::jobListFor($driver, $sunday)->pluck('id')->all());

        // On Monday all three are carried over in the driver's order, still
        // ahead of Monday's own stop.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 00:01', 'Asia/Kuala_Lumpur'));
        $monday = Order::factory()->assigned($driver)->create(['scheduled_for' => '2026-10-05', 'route_position' => 1, 'postcode' => '10000']);

        $this->assertSame(
            [$first->id, $second->id, $leftOver->id, $monday->id],
            Order::jobListFor($driver, $sunday->addDay())->pluck('id')->all(),
        );
    }

    public function test_the_delivery_area_is_one_line_of_plain_text()
    {
        $area = fn (string $city) => (new Order)->forceFill(['city' => $city, 'postcode' => '50450'])->deliveryArea();

        // The postcode stays with the town's last word.
        $this->assertSame("Kuala Lumpur\u{00A0}50450", $area('Kuala Lumpur'));
        // From an order placed before the city was checked for these characters.
        $this->assertSame("Kuala Lumpur Sign in (https://example.com) b\u{00A0}50450", $area("Kuala | Lumpur\n[Sign in](https://example.com) <b>"));
    }

    public function test_customers_list_their_own_orders()
    {
        $customer = User::factory()->create();
        $mine = Order::factory()->for($customer, 'customer')->create();
        Order::factory()->create();

        $this->assertSame([$mine->id], Order::forCustomer($customer)->pluck('id')->all());
    }

    public function test_search_by_tracking_number_or_name()
    {
        $order = Order::factory()->create(['tracking_number' => 'KT7Q4M92XD', 'receiver_name' => 'Daniel Lim']);
        Order::factory()->create(['receiver_name' => 'Someone Else']);

        $this->assertSame([$order->id], Order::search('kt-7q4m92xd')->pluck('id')->all());
        $this->assertSame([$order->id], Order::search('daniel')->pluck('id')->all());
        $this->assertCount(2, Order::search('')->get());
    }

    public function test_unclaimed_orders_are_created_orders_past_the_limit()
    {
        $old = Order::factory()->create(['created_at' => now()->subDays(15)]);
        Order::factory()->create(['created_at' => now()->subDays(2)]);
        Order::factory()->cancelled()->create(['created_at' => now()->subDays(30)]);

        $this->assertSame([$old->id], Order::unclaimed()->pluck('id')->all());
    }

    public function test_the_nearest_active_branch_is_found()
    {
        $pj = Branch::factory()->create(['code' => 'PJ-SS2', 'latitude' => 3.1185, 'longitude' => 101.6225]);
        Branch::factory()->create(['code' => 'SA-S13', 'latitude' => 3.0850, 'longitude' => 101.5390]);
        Branch::factory()->inactive()->create(['code' => 'CLOSED', 'latitude' => 3.1180, 'longitude' => 101.6220]);

        // Standing in SS2 itself, the closed branch next door is ignored.
        $this->assertTrue($pj->is(Branch::nearestTo(3.1181, 101.6221)));
        $this->assertEqualsWithDelta(0.0, $pj->distanceKmFrom(3.1185, 101.6225), 0.001);
    }

    public function test_factory_states_produce_consistent_orders()
    {
        $delivered = Order::factory()->delivered()->create();

        $this->assertSame(OrderStatus::Delivered, $delivered->status);
        $this->assertNotNull($delivered->payment);
        $this->assertSame($delivered->final_price_sen, $delivered->payment->amount_sen);
        $this->assertNotNull($delivered->driver_id);
        $this->assertSame(1, $delivered->deliveryAttempts()->count());
        $this->assertSame(
            ['created', 'dropped_off', 'paid', 'assigned', 'picked_up', 'delivered'],
            $delivered->statusEvents->map(fn ($event) => $event->to_status->value)->all(),
        );

        $returned = Order::factory()->returnedToSender()->create();
        $this->assertSame(1, $returned->failedAttemptsCount());
        $this->assertSame(OrderStatus::ReturnedToSender, $returned->statusEvents->last()?->to_status);
    }
}
