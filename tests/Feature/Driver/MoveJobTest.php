<?php

namespace Tests\Feature\Driver;

use App\Actions\Delivery\BroadcastDeliveryProgress;
use App\Events\DeliveryProgressUpdated;
use App\Events\DeliveryRunReordered;
use App\Models\Order;
use App\Models\User;
use App\Support\DeliveryProgress;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MoveJobTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Kuala_Lumpur'));
        $this->driver = User::factory()->driver()->create();
    }

    public function test_drivers_move_a_stop_up_and_down_their_list_for_today()
    {
        [$a, $b, $c] = $this->stops(3);

        $this->move($c, 'up')
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('driver.jobs'));
        $this->assertPositions([$a, $c, $b]);

        $this->move($a, 'down')->assertSessionHasNoErrors();
        $this->assertPositions([$c, $a, $b]);

        // My jobs lists the stops in the new order.
        $this->actingAs($this->driver)
            ->get(route('driver.jobs'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.0.id', $c->id)
                ->where('jobs.1.id', $a->id)
                ->where('jobs.2.id', $b->id));
    }

    public function test_the_places_are_numbered_again_without_gaps()
    {
        $a = $this->job(['route_position' => 2]);
        $b = $this->job(['route_position' => 5]);
        $c = $this->job(['route_position' => 9]);
        // Two stops dispatched at the same moment share a place until one moves.
        $d = $this->job(['route_position' => 9, 'postcode' => '99999']);

        $this->move($b, 'down')->assertSessionHasNoErrors();

        $this->assertSame([1, 2, 3, 4], [$a->refresh()->route_position, $c->refresh()->route_position, $b->refresh()->route_position, $d->refresh()->route_position]);
    }

    public function test_the_first_stop_cannot_move_up_nor_the_last_one_down()
    {
        [$a, $b, $c] = $this->stops(3);

        $this->move($a, 'up')->assertSessionHasErrors(['direction' => 'This stop is already first.']);
        $this->move($c, 'down')->assertSessionHasErrors(['direction' => 'This stop is already last.']);

        $this->assertPositions([$a, $b, $c]);
    }

    public function test_a_job_carried_over_moves_down_past_todays_stops_and_the_list_joins_todays_run()
    {
        $older = $this->job(['route_position' => 2, 'scheduled_for' => '2026-10-03'], pickedUp: true);
        $overdue = $this->job(['route_position' => 1, 'scheduled_for' => '2026-10-04'], pickedUp: true);
        $a = $this->job(['route_position' => 1], pickedUp: true);
        $b = $this->job(['route_position' => 2]);
        $c = $this->job(['route_position' => 3], pickedUp: true);

        // Until the driver moves a stop, the jobs carried over come first, by
        // the run each was on.
        $this->assertSame([$older->id, $overdue->id, $a->id, $b->id, $c->id], $this->listed());

        $this->move($overdue, 'down')->assertSessionHasNoErrors();

        // The whole list is numbered again and placed on today's run, the
        // carried-over job that did not move included.
        $this->assertPositions([$older, $a, $overdue, $b, $c]);

        $this->move($overdue, 'down')->assertSessionHasNoErrors();
        $this->move($overdue, 'down')->assertSessionHasNoErrors();
        $this->assertPositions([$older, $a, $b, $c, $overdue]);
        $this->move($overdue, 'down')->assertSessionHasErrors(['direction' => 'This stop is already last.']);

        // The stops follow the new order; the parcel still to collect does not count.
        $this->assertSame([
            $older->id => ['position' => 1, 'stops_before' => 0],
            $a->id => ['position' => 2, 'stops_before' => 1],
            $c->id => ['position' => 3, 'stops_before' => 2],
            $overdue->id => ['position' => 4, 'stops_before' => 3],
        ], app(DeliveryProgress::class)->forRun($this->driver, CarbonImmutable::parse('2026-10-05')));

        // My jobs lists it last, still overdue, and it moves back up the same way.
        $this->actingAs($this->driver)
            ->get(route('driver.jobs'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.4.id', $overdue->id)
                ->where('jobs.4.is_overdue', true));

        $this->move($overdue, 'up')->assertSessionHasNoErrors();
        $this->assertPositions([$older, $a, $b, $overdue, $c]);
    }

    public function test_customers_behind_an_overdue_parcel_left_until_last_are_told_the_right_count()
    {
        $overdue = $this->job(['route_position' => 1, 'scheduled_for' => '2026-10-04'], pickedUp: true);
        $first = $this->job(['route_position' => 1, 'tracking_number' => 'KT7Q4M92XD'], pickedUp: true);
        $second = $this->job(['route_position' => 2], pickedUp: true);
        $progress = app(DeliveryProgress::class);

        // The overdue parcel comes first, and each page has heard its stop.
        app(BroadcastDeliveryProgress::class)->handle($this->driver, CarbonImmutable::parse('2026-10-05'));
        $this->assertSame(['position' => 2, 'stops_before' => 1], $progress->forOrder($first));
        Event::fake([DeliveryProgressUpdated::class]);

        // The driver leaves it until last.
        $this->move($overdue, 'down')->assertSessionHasNoErrors();
        $this->move($overdue, 'down')->assertSessionHasNoErrors();

        $this->assertSame(['position' => 1, 'stops_before' => 0], $progress->forOrder($first->refresh()));
        $this->assertSame(['position' => 2, 'stops_before' => 1], $progress->forOrder($second->refresh()));
        $this->assertSame(['position' => 3, 'stops_before' => 2], $progress->forOrder($overdue->refresh()));

        // The tracking page of the parcel behind it says "You're next".
        $this->get(route('track', ['number' => 'KT-7Q4M92XD']))
            ->assertInertia(fn (Assert $page) => $page->where('result.progress', ['position' => 1, 'stops_before' => 0]));

        // Each move sent only the numbers that changed, as they changed.
        $this->assertSame([
            ["orders.{$first->id}", ['position' => 1, 'stops_before' => 0, 'status' => 'picked_up']],
            ["orders.{$overdue->id}", ['position' => 2, 'stops_before' => 1, 'status' => 'picked_up']],
            ["orders.{$second->id}", ['position' => 2, 'stops_before' => 1, 'status' => 'picked_up']],
            ["orders.{$overdue->id}", ['position' => 3, 'stops_before' => 2, 'status' => 'picked_up']],
        ], Event::dispatched(DeliveryProgressUpdated::class)->map(fn (array $call) => [$call[0]->privateChannel, $call[0]->progress])->values()->all());
    }

    public function test_only_todays_list_can_be_put_in_order()
    {
        $a = $this->job(['route_position' => 1, 'scheduled_for' => '2026-10-06']);
        $b = $this->job(['route_position' => 2, 'scheduled_for' => '2026-10-06']);

        $this->move($b, 'up')->assertSessionHasErrors(['direction' => "You can only reorder today's stops."]);

        $this->assertSame([1, 2], [$a->refresh()->route_position, $b->refresh()->route_position]);
    }

    public function test_a_finished_job_is_no_longer_on_the_list()
    {
        $this->stops(1);
        $delivered = Order::factory()->delivered($this->driver)->create();

        $this->move($delivered, 'up')->assertSessionHasErrors(['direction' => 'This delivery is no longer on your list.']);
    }

    public function test_drivers_cannot_move_another_drivers_stop()
    {
        $theirs = Order::factory()->assigned()->create(['route_position' => 2]);
        Order::factory()->assigned($theirs->driver)->create(['route_position' => 1]);

        $this->move($theirs, 'up')->assertForbidden();

        $this->assertSame(2, $theirs->refresh()->route_position);
    }

    public function test_a_move_leaves_other_drivers_lists_alone_and_their_carried_over_jobs_cannot_be_moved()
    {
        Event::fake([DeliveryProgressUpdated::class]);
        $siti = User::factory()->driver()->create();
        $sitisLeftOver = Order::factory()->pickedUp($siti)->create(['scheduled_for' => '2026-10-04', 'route_position' => 1, 'postcode' => '50000']);
        $sitis = Order::factory()->pickedUp($siti)->create(['scheduled_for' => '2026-10-05', 'route_position' => 1, 'postcode' => '50000']);
        $overdue = $this->job(['route_position' => 1, 'scheduled_for' => '2026-10-04'], pickedUp: true);
        $mine = $this->job(['route_position' => 1], pickedUp: true);

        $this->move($overdue, 'down')->assertSessionHasNoErrors();
        $this->move($sitisLeftOver, 'down')->assertForbidden();

        // Only the driver's own list is numbered again and put on today's run.
        $this->assertPositions([$mine, $overdue]);
        $runs = collect([$sitisLeftOver, $sitis])
            ->map(fn (Order $order) => $order->refresh())
            ->map(fn (Order $order) => [$order->route_position, $order->route_date?->toDateString()])
            ->all();
        $this->assertSame([[1, '2026-10-04'], [1, '2026-10-05']], $runs);

        // And none of Siti's parcels heard a thing.
        Event::assertDispatched(DeliveryProgressUpdated::class);
        Event::assertNotDispatched(DeliveryProgressUpdated::class, fn (DeliveryProgressUpdated $event) => in_array(
            $event->privateChannel,
            [DeliveryProgress::privateChannel($sitisLeftOver), DeliveryProgress::privateChannel($sitis)],
            true,
        ));
    }

    public function test_other_roles_cannot_move_stops()
    {
        [, $b] = $this->stops(2);

        $this->post(route('driver.jobs.move', $b), ['direction' => 'up'])->assertRedirect(route('login'));

        foreach ([$b->customer, User::factory()->staff()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->post(route('driver.jobs.move', $b), ['direction' => 'up'])->assertForbidden();
        }

        $this->assertSame(2, $b->refresh()->route_position);
    }

    public function test_the_direction_must_be_up_or_down()
    {
        [, $b] = $this->stops(2);

        $this->move($b, '')->assertSessionHasErrors(['direction' => 'The direction field is required.']);
        $this->move($b, 'first')->assertSessionHasErrors('direction');
        $this->assertSame(2, $b->refresh()->route_position);
    }

    public function test_moves_are_limited_to_sixty_a_minute_for_each_driver()
    {
        [$a, $b] = $this->stops(2);

        // Refused moves count too: each one is a request.
        for ($i = 0; $i < 60; $i++) {
            $this->move($a, 'up')->assertSessionHasErrors('direction');
        }

        $this->move($b, 'up')->assertTooManyRequests();
        $this->assertPositions([$a, $b]);

        // Another driver's list is not held up.
        $siti = User::factory()->driver()->create();
        $theirs = Order::factory()->assigned($siti)->create(['route_position' => 2, 'scheduled_for' => '2026-10-05']);
        Order::factory()->assigned($siti)->create(['route_position' => 1, 'scheduled_for' => '2026-10-05']);
        $this->actingAs($siti)->from(route('driver.jobs'))->post(route('driver.jobs.move', $theirs), ['direction' => 'up'])->assertSessionHasNoErrors();

        // A minute later the driver can move stops again.
        $this->travel(61)->seconds();
        $this->move($b, 'up')->assertSessionHasNoErrors();
        $this->assertPositions([$b, $a]);
    }

    public function test_a_move_does_not_count_as_an_update_to_the_order()
    {
        [$a, $b] = $this->stops(2);
        $updated = $a->updated_at?->toIso8601String();
        $this->travel(5)->minutes();

        $this->move($b, 'up')->assertSessionHasNoErrors();

        $this->assertSame($updated, $a->refresh()->updated_at?->toIso8601String());
    }

    public function test_the_parcels_on_the_van_hear_their_new_stops_once_the_move_is_saved()
    {
        Event::fake([DeliveryProgressUpdated::class]);
        $a = $this->job(['route_position' => 1], pickedUp: true);
        $b = $this->job(['route_position' => 2]);
        $c = $this->job(['route_position' => 3], pickedUp: true);

        $this->move($c, 'up')->assertSessionHasNoErrors();
        // Still behind A: nothing changed for anyone on the van, but the
        // first check tells each one where it is.
        Event::assertDispatchedTimes(DeliveryProgressUpdated::class, 2);

        $this->move($c, 'up')->assertSessionHasNoErrors();

        Event::assertDispatchedTimes(DeliveryProgressUpdated::class, 4);
        Event::assertDispatched(DeliveryProgressUpdated::class, fn (DeliveryProgressUpdated $event) => $event->privateChannel === "orders.{$c->id}"
            && $event->progress === ['position' => 1, 'stops_before' => 0, 'status' => 'picked_up']);
        Event::assertDispatched(DeliveryProgressUpdated::class, fn (DeliveryProgressUpdated $event) => $event->privateChannel === "orders.{$a->id}"
            && $event->progress === ['position' => 2, 'stops_before' => 1, 'status' => 'picked_up']);
        Event::assertNotDispatched(DeliveryProgressUpdated::class, fn (DeliveryProgressUpdated $event) => $event->privateChannel === "orders.{$b->id}");
    }

    public function test_a_saved_move_is_announced_and_a_refused_one_is_not()
    {
        Event::fake([DeliveryRunReordered::class]);
        [$a, $b] = $this->stops(2);

        $this->move($a, 'up');
        Event::assertNotDispatched(DeliveryRunReordered::class);

        $this->move($b, 'up');
        Event::assertDispatched(DeliveryRunReordered::class, fn (DeliveryRunReordered $event) => $event->driver->is($this->driver)
            && $event->date->toDateString() === '2026-10-05');
    }

    /**
     * Create the given number of stops on the driver's list for today, at places 1, 2, 3…
     *
     * @return list<Order>
     */
    private function stops(int $count): array
    {
        return array_map(fn (int $place): Order => $this->job(['route_position' => $place]), range(1, $count));
    }

    /**
     * Get the driver's list for today in its order.
     *
     * @return Collection<int, Order>
     */
    private function stopsInOrder(): Collection
    {
        return Order::query()->jobListFor($this->driver, CarbonImmutable::parse('2026-10-05', 'Asia/Kuala_Lumpur'))->get();
    }

    /**
     * Get the ids of the driver's list for today in its order.
     *
     * @return list<int>
     */
    private function listed(): array
    {
        return $this->stopsInOrder()->modelKeys();
    }

    /**
     * Assert that the driver's list for today is in the given order, at
     * places 1, 2, 3… of today's run.
     *
     * @param  list<Order>  $expected
     */
    private function assertPositions(array $expected): void
    {
        $stops = $this->stopsInOrder();

        $this->assertSame(array_map(fn (Order $order) => $order->id, $expected), $stops->modelKeys());
        $this->assertSame(range(1, $stops->count()), $stops->pluck('route_position')->all());
        $this->assertSame(['2026-10-05'], $stops->map(fn (Order $order) => $order->route_date?->toDateString())->unique()->values()->all());
    }

    /**
     * Create a job of the driver's, scheduled for today unless given.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function job(array $attributes = [], bool $pickedUp = false): Order
    {
        $factory = $pickedUp ? Order::factory()->pickedUp($this->driver) : Order::factory()->assigned($this->driver);

        return $factory->create(['postcode' => '50000', 'scheduled_for' => '2026-10-05', ...$attributes]);
    }

    /**
     * Ask to move the stop as the driver would on My jobs.
     */
    private function move(Order $order, string $direction): TestResponse
    {
        return $this->actingAs($this->driver)
            ->from(route('driver.jobs'))
            ->post(route('driver.jobs.move', $order), ['direction' => $direction]);
    }
}
