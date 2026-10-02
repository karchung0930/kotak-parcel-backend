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
