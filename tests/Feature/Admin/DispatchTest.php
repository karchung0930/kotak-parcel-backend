<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Models\DeliveryAttempt;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DispatchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->admin = User::factory()->admin()->create();
        $this->today = CarbonImmutable::today(config('kotak.timezone'));
    }

    protected function tearDown(): void
    {
        // A static setting: do not let it leak into other tests.
        Model::preventLazyLoading(false);

        parent::tearDown();
    }

    public function test_the_board_lists_parcels_to_dispatch_failed_deliveries_and_drivers()
    {
        $busy = User::factory()->driver()->create(['name' => 'Ahmad']);
        $free = User::factory()->driver()->create(['name' => 'Bala']);
        User::factory()->driver()->inactive()->create();

        $first = Order::factory()->paid()->create(['paid_at' => now()->subHours(2)]);
        $second = Order::factory()->paid()->create(['paid_at' => now()->subHour()]);
        $failed = Order::factory()->deliveryFailed($busy)->create();
        Order::factory()->assigned($busy)->create();
        Order::factory()->delivered($busy)->create();
        Order::factory()->created()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.dispatch'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Dispatch')
                ->where('date', $this->today->toDateString())
                ->where('today', $this->today->toDateString())
                ->where('maxFailedAttempts', 3)
                ->has('awaiting.data', 2)
                ->where('awaiting.data.0.id', $first->id)
                ->where('awaiting.data.1.id', $second->id)
                ->has('awaiting.data.0.branch')
                ->where('awaiting.meta.total', 2)
                ->has('failed.data', 1)
                ->where('failed.data.0.id', $failed->id)
                ->where('failed.data.0.failed_attempts', 1)
                ->where('failed.data.0.latest_attempt.outcome.value', 'failed')
                ->where('failed.data.0.latest_attempt.failure_reason.value', 'recipient_unavailable')
                ->has('failed.data.0.driver')
                // A list row: the full contact details are on the order page.
                ->missing('failed.data.0.receiver_phone')
                ->has('overdue.data', 0)
                ->has('drivers', 2)
                ->where('drivers.0.id', $busy->id)
                ->where('drivers.0.jobs_count', 1)
                ->where('drivers.1.id', $free->id)
                ->where('drivers.1.jobs_count', 0));
    }

    public function test_deliveries_left_open_after_their_day_are_listed_as_overdue()
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-30 10:00', 'Asia/Kuala_Lumpur'));
        $driver = User::factory()->driver()->create();
        $inTheVan = Order::factory()->pickedUp($driver)->create(['scheduled_for' => '2026-09-29']);
        $notCollected = Order::factory()->assigned($driver)->create(['scheduled_for' => '2026-09-28']);
        Order::factory()->assigned($driver)->create(['scheduled_for' => '2026-09-30']);
        Order::factory()->delivered($driver)->create(['scheduled_for' => '2026-09-28']);

        $this->actingAs($this->admin)
            ->get(route('admin.dispatch'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('overdue.data', 2)
                ->where('overdue.data.0.id', $notCollected->id)
                ->where('overdue.data.0.scheduled_for', '2026-09-28')
                ->where('overdue.data.0.driver.id', $driver->id)
                ->has('overdue.data.0.branch')
                ->where('overdue.data.1.id', $inTheVan->id)
                ->where('overdue.data.1.status.value', 'picked_up'));
    }

    public function test_each_queue_is_paginated_on_its_own()
    {
        $paid = Order::factory()->count(21)->paid()->create();
        Order::factory()->deliveryFailed()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.dispatch', ['awaiting_page' => 2]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('awaiting.data', 1)
                ->where('awaiting.data.0.id', $paid->last()?->id)
                ->where('awaiting.meta.total', 21)
                ->where('awaiting.meta.current_page', 2)
                ->has('failed.data', 1)
                ->where('failed.meta.current_page', 1)
                ->where('awaiting.links.prev', fn (string $prev) => str_contains($prev, 'awaiting_page=1')));
    }

    public function test_the_board_lists_the_open_deliveries_of_the_chosen_day_by_driver()
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-29 10:00', 'Asia/Kuala_Lumpur'));
        $bala = User::factory()->driver()->create(['name' => 'Bala']);
        $ahmad = User::factory()->driver()->create(['name' => 'Ahmad']);
        $balasJob = Order::factory()->assigned($bala)->create(['scheduled_for' => '2026-09-30']);
        $inTheVan = Order::factory()->pickedUp($ahmad)->create(['scheduled_for' => '2026-09-30']);
        $toCollect = Order::factory()->assigned($ahmad)->create(['scheduled_for' => '2026-09-30']);
        Order::factory()->assigned($ahmad)->create(['scheduled_for' => '2026-09-29']);
        Order::factory()->delivered($ahmad)->create(['scheduled_for' => '2026-09-30']);

        $this->actingAs($this->admin)
            ->get(route('admin.dispatch', ['date' => '2026-09-30']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('date', '2026-09-30')
                ->has('scheduled.data', 3)
                ->where('scheduled.data.0.id', $inTheVan->id)
                ->where('scheduled.data.0.status.value', 'picked_up')
                ->where('scheduled.data.1.id', $toCollect->id)
                ->where('scheduled.data.1.driver.name', 'Ahmad')
                ->has('scheduled.data.1.branch')
                ->where('scheduled.data.1.failed_attempts', 0)
                ->where('scheduled.data.2.id', $balasJob->id)
                ->where('scheduled.meta.total', 3)
                ->where('drivers.0.jobs_count', 2)
                ->where('drivers.1.jobs_count', 1));
    }

    public function test_a_partial_reload_for_another_day_only_sends_what_changes()
    {
        $driver = User::factory()->driver()->create();
        $tomorrow = $this->today->addDay()->toDateString();
        Order::factory()->assigned($driver)->create(['scheduled_for' => $tomorrow]);
        Order::factory()->paid()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.dispatch', ['date' => $tomorrow]))
            ->assertInertia(fn (Assert $page) => $page
                ->reloadOnly(['drivers', 'date', 'scheduled'], fn (Assert $reload) => $reload
                    ->where('date', $tomorrow)
                    ->where('drivers.0.jobs_count', 1)
                    ->has('scheduled.data', 1)
                    ->missing('awaiting')
                    ->missing('failed')
                    ->missing('overdue')));
    }

    public function test_driver_workload_is_counted_for_the_chosen_day()
    {
        $driver = User::factory()->driver()->create();
        Order::factory()->assigned($driver)->create(['scheduled_for' => $this->today->addDay()->toDateString()]);

        $this->actingAs($this->admin)
            ->get(route('admin.dispatch', ['date' => $this->today->addDay()->toDateString()]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('date', $this->today->addDay()->toDateString())
                ->where('drivers.0.jobs_count', 1));

        $this->actingAs($this->admin)
            ->get(route('admin.dispatch'))
            ->assertInertia(fn (Assert $page) => $page->where('drivers.0.jobs_count', 0));
    }

    public function test_an_invalid_date_is_rejected()
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dispatch', ['date' => '2026-02-30']))
            ->assertSessionHasErrors('date');
    }

    public function test_the_board_eager_loads_its_relations()
    {
        Model::preventLazyLoading();

        $driver = User::factory()->driver()->create();
        Order::factory()->paid()->create();
        Order::factory()->deliveryFailed($driver)->create();

        $few = $this->countQueries(fn () => $this->actingAs($this->admin)->get(route('admin.dispatch'))->assertOk());

        Order::factory()->count(5)->paid()->create();
        Order::factory()->count(5)->deliveryFailed()->create();
        User::factory()->count(3)->driver()->create();

        $many = $this->countQueries(fn () => $this->actingAs($this->admin)
            ->get(route('admin.dispatch'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('awaiting.data', 6)
                ->has('awaiting.data.5.branch')
                ->has('failed.data', 6)
                ->has('failed.data.5.branch')
                ->has('failed.data.5.driver.vehicle_plate')
                ->has('failed.data.5.latest_attempt.failure_reason')
                ->has('failed.data.5.failed_attempts')
                ->has('overdue.data', 0)
                ->has('drivers', 9)
                ->has('drivers.8.jobs_count')));

        $this->assertSame($few, $many);
    }

    public function test_admins_schedule_a_paid_parcel_with_a_driver()
    {
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->paid()->create();
        $date = $this->today->addDay();

        $this->actingAs($this->admin)
            ->from(route('admin.dispatch'))
            ->post(route('admin.orders.assign', $order), [
                'driver_id' => $driver->id,
                'scheduled_for' => $date->toDateString(),
            ])
            ->assertRedirect(route('admin.dispatch'))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $order->refresh();
        $this->assertSame(OrderStatus::Assigned, $order->status);
        $this->assertSame($driver->id, $order->driver_id);
        $this->assertSame($date->toDateString(), $order->scheduled_for?->toDateString());
    }

    public function test_a_parcel_can_be_scheduled_for_today_in_malaysia()
    {
        // 23:30 UTC is already the next day in Kuala Lumpur.
        $this->travelTo(CarbonImmutable::parse('2026-09-29 23:30:00', 'UTC'));
        $order = Order::factory()->paid()->create();

        $this->actingAs($this->admin)->post(route('admin.orders.assign', $order), [
            'driver_id' => User::factory()->driver()->create()->id,
            'scheduled_for' => '2026-09-30',
        ])->assertSessionHasNoErrors();

        $this->assertSame('2026-09-30', $order->refresh()->scheduled_for?->toDateString());
    }

    public function test_the_delivery_date_cannot_be_in_the_past()
    {
        $order = Order::factory()->paid()->create();

        $this->actingAs($this->admin)->post(route('admin.orders.assign', $order), [
            'driver_id' => User::factory()->driver()->create()->id,
            'scheduled_for' => $this->today->subDay()->toDateString(),
        ])->assertSessionHasErrors('scheduled_for');

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unavailableDrivers(): array
    {
        return ['inactive driver' => ['inactive'], 'branch staff' => ['staff'], 'customer' => ['customer'], 'unknown user' => ['missing']];
    }

    #[DataProvider('unavailableDrivers')]
    public function test_only_active_drivers_can_be_assigned(string $who)
    {
        $order = Order::factory()->paid()->create();
        $driverId = match ($who) {
            'inactive' => User::factory()->driver()->inactive()->create()->id,
            'staff' => User::factory()->staff()->create()->id,
            'customer' => $order->customer_id,
            'missing' => 999999,
        };

        $this->actingAs($this->admin)->post(route('admin.orders.assign', $order), [
            'driver_id' => $driverId,
            'scheduled_for' => $this->today->toDateString(),
        ])->assertSessionHasErrors('driver_id');

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertNull($order->driver_id);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unassignableStatuses(): array
    {
        return [
            'created' => ['created'],
            'dropped off' => ['droppedOff'],
            'out for delivery' => ['pickedUp'],
            'delivered' => ['delivered'],
            'cancelled' => ['cancelled'],
        ];
    }

    #[DataProvider('unassignableStatuses')]
    public function test_only_paid_failed_and_not_yet_collected_parcels_can_be_assigned(string $state)
    {
        $order = Order::factory()->{$state}()->create();
        $status = $order->status;

        $this->actingAs($this->admin)
            ->from(route('admin.dispatch'))
            ->post(route('admin.orders.assign', $order), [
                'driver_id' => User::factory()->driver()->create()->id,
                'scheduled_for' => $this->today->toDateString(),
            ])
            ->assertRedirect(route('admin.dispatch'))
            ->assertSessionHasErrors('status');

        $this->assertSame($status, $order->refresh()->status);
    }

    public function test_a_failed_delivery_is_rescheduled_while_attempts_remain()
    {
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->deliveryFailed()->create();

        $this->actingAs($this->admin)->post(route('admin.orders.assign', $order), [
            'driver_id' => $driver->id,
            'scheduled_for' => $this->today->addDay()->toDateString(),
        ])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(OrderStatus::Assigned, $order->status);
        $this->assertSame($driver->id, $order->driver_id);
    }

    public function test_admins_reassign_a_delivery_before_it_is_picked_up()
    {
        $first = User::factory()->driver()->create();
        $second = User::factory()->driver()->create();
        $order = Order::factory()->assigned($first)->create(['scheduled_for' => $this->today->toDateString()]);
        $tomorrow = $this->today->addDay();

        $this->actingAs($this->admin)
            ->from(route('admin.dispatch'))
            ->post(route('admin.orders.assign', $order), [
                'driver_id' => $second->id,
                'scheduled_for' => $tomorrow->toDateString(),
            ])
            ->assertRedirect(route('admin.dispatch'))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $order->refresh();
        $this->assertSame(OrderStatus::Assigned, $order->status);
        $this->assertSame($second->id, $order->driver_id);
        $this->assertSame($tomorrow->toDateString(), $order->scheduled_for?->toDateString());

        $event = $order->latestStatusEvent()->firstOrFail();
        $this->assertSame(OrderStatus::Assigned, $event->from_status);
        $this->assertSame(OrderStatus::Assigned, $event->to_status);
        $this->assertSame('Rescheduled for delivery on '.$tomorrow->format('j M Y').'.', $event->note);
        $this->assertSame($this->admin->id, $event->actor_id);
    }

    public function test_a_reassigned_job_moves_from_one_drivers_list_to_the_others()
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-29 10:00', 'Asia/Kuala_Lumpur'));
        $first = User::factory()->driver()->create(['name' => 'Ahmad']);
        $second = User::factory()->driver()->create(['name' => 'Bala']);
        $order = Order::factory()->assigned($first)->create(['scheduled_for' => '2026-09-29']);

        $this->actingAs($this->admin)->post(route('admin.orders.assign', $order), [
            'driver_id' => $second->id,
            'scheduled_for' => '2026-09-30',
        ])->assertSessionHasNoErrors();

        $this->actingAs($first)
            ->get(route('driver.jobs'))
            ->assertInertia(fn (Assert $page) => $page->has('jobs', 0)->where('counts.assigned', 0));
        $this->actingAs($first)->get(route('driver.jobs.show', $order))->assertForbidden();
        $this->actingAs($first)->post(route('driver.jobs.pickup', $order))->assertForbidden();

        $this->actingAs($second)
            ->get(route('driver.jobs', ['date' => '2026-09-30']))
            ->assertInertia(fn (Assert $page) => $page->has('jobs', 1)->where('jobs.0.id', $order->id));
        $this->actingAs($second)->post(route('driver.jobs.pickup', $order))->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::PickedUp, $order->refresh()->status);
    }

    public function test_a_delivery_is_not_reassigned_to_the_same_driver_and_day()
    {
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->assigned($driver)->create(['scheduled_for' => $this->today->toDateString()]);
        $events = $order->statusEvents()->count();

        $this->actingAs($this->admin)->post(route('admin.orders.assign', $order), [
            'driver_id' => $driver->id,
            'scheduled_for' => $this->today->toDateString(),
        ])->assertSessionHasErrors(['driver_id' => 'This parcel is already scheduled with this driver on that day. Choose another driver or date.']);

        $this->assertSame($events, $order->statusEvents()->count());
    }

    public function test_a_delivery_cannot_be_reassigned_once_the_driver_has_picked_it_up()
    {
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->pickedUp($driver)->create();

        $this->actingAs($this->admin)->post(route('admin.orders.assign', $order), [
            'driver_id' => User::factory()->driver()->create()->id,
            'scheduled_for' => $this->today->addDay()->toDateString(),
        ])->assertSessionHasErrors(['status' => 'This parcel is currently "Picked Up" and cannot be changed to "Assigned".']);

        $order->refresh();
        $this->assertSame(OrderStatus::PickedUp, $order->status);
        $this->assertSame($driver->id, $order->driver_id);
    }

    public function test_a_failed_delivery_at_the_attempt_limit_must_be_returned_to_the_sender()
    {
        $order = Order::factory()->deliveryFailed()->create();
        DeliveryAttempt::factory()->failed()->count(2)->for($order)->create(['driver_id' => $order->driver_id]);

        $this->actingAs($this->admin)->post(route('admin.orders.assign', $order), [
            'driver_id' => User::factory()->driver()->create()->id,
            'scheduled_for' => $this->today->toDateString(),
        ])->assertSessionHasErrors('status');

        $this->assertSame(OrderStatus::DeliveryFailed, $order->refresh()->status);

        $this->actingAs($this->admin)
            ->post(route('admin.orders.return', $order))
            ->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::ReturnedToSender, $order->refresh()->status);
    }

    public function test_admins_return_a_failed_delivery_to_the_sender()
    {
        $order = Order::factory()->deliveryFailed()->create();

        $this->actingAs($this->admin)
            ->from(route('admin.dispatch'))
            ->post(route('admin.orders.return', $order), ['note' => 'Recipient moved away.'])
            ->assertRedirect(route('admin.dispatch'))
            ->assertInertiaFlash('toast.type', 'success');

        $order->refresh();
        $this->assertSame(OrderStatus::ReturnedToSender, $order->status);
        $this->assertSame('Recipient moved away.', $order->latestStatusEvent()->firstOrFail()->note);
        $this->assertSame($this->admin->id, $order->latestStatusEvent()->firstOrFail()->actor_id);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unreturnableStatuses(): array
    {
        return [
            'paid' => ['paid'],
            'assigned' => ['assigned'],
            'out for delivery' => ['pickedUp'],
            'delivered' => ['delivered'],
            'already returned' => ['returnedToSender'],
        ];
    }

    #[DataProvider('unreturnableStatuses')]
    public function test_only_failed_deliveries_can_be_returned_to_the_sender(string $state)
    {
        $order = Order::factory()->{$state}()->create();
        $status = $order->status;

        $this->actingAs($this->admin)
            ->post(route('admin.orders.return', $order))
            ->assertSessionHasErrors('status');

        $this->assertSame($status, $order->refresh()->status);
    }

    public function test_api_clients_get_a_conflict_for_an_invalid_return()
    {
        $order = Order::factory()->delivered()->create();

        $this->actingAs($this->admin)
            ->postJson(route('admin.orders.return', $order))
            ->assertConflict();
    }

    /**
     * Count the database queries run by the callback.
     */
    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
