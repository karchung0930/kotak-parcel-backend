<?php

namespace Tests\Feature\Delivery;

use App\Actions\Delivery\AssignDriver;
use App\Actions\Delivery\BroadcastDeliveryProgress;
use App\Actions\Delivery\MarkPickedUp;
use App\Actions\Delivery\MoveJob;
use App\Actions\Delivery\RecordDeliveryFailure;
use App\Enums\DeliveryFailureReason;
use App\Enums\MoveDirection;
use App\Enums\OrderStatus;
use App\Events\DeliveryProgressUpdated;
use App\Listeners\SendDeliveryProgress;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderStatusService;
use App\Support\DeliveryProgress;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class DeliveryProgressBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Kuala_Lumpur'));
        $this->driver = User::factory()->driver()->create();
    }

    public function test_a_parcel_picked_up_hears_its_stop_on_its_own_two_channels()
    {
        Event::fake([DeliveryProgressUpdated::class]);
        $order = $this->job(1);

        $this->actingAs($this->driver)->post(route('driver.jobs.pickup', $order))->assertSessionHasNoErrors();

        Event::assertDispatchedTimes(DeliveryProgressUpdated::class, 1);
        Event::assertDispatched(DeliveryProgressUpdated::class, function (DeliveryProgressUpdated $event) use ($order): bool {
            $this->assertSame(
                [DeliveryProgress::publicChannel($order), "private-orders.{$order->id}"],
                array_map(fn ($channel) => $channel->name, $event->broadcastOn()),
            );
            $this->assertSame('delivery.progress', $event->broadcastAs());
            $this->assertSame(['position' => 1, 'stops_before' => 0, 'status' => 'picked_up'], $event->broadcastWith());

            return true;
        });
    }

    public function test_a_delivery_moves_the_parcels_behind_it_up_and_tells_the_delivered_one()
    {
        [$first, $second, $third] = $this->onTheVan(3);
        Event::fake([DeliveryProgressUpdated::class]);

        $this->actingAs($this->driver)->post(route('driver.jobs.deliver', $first), [
            'recipient_name' => 'Daniel Lim',
            'photo' => UploadedFile::fake()->image('door.png'),
        ])->assertSessionHasNoErrors();

        $this->assertSent([
            $first->id => ['position' => null, 'stops_before' => null, 'status' => 'delivered'],
            $second->id => ['position' => 1, 'stops_before' => 0, 'status' => 'picked_up'],
            $third->id => ['position' => 2, 'stops_before' => 1, 'status' => 'picked_up'],
        ]);
    }

    public function test_a_failed_delivery_moves_the_parcels_behind_it_up_and_tells_the_failed_one()
    {
        [, $second, $third] = $this->onTheVan(3);
        Event::fake([DeliveryProgressUpdated::class]);

        $this->actingAs($this->driver)->post(route('driver.jobs.fail', $second), [
            'reason' => 'recipient_unavailable',
        ])->assertSessionHasNoErrors();

        // The first stop is still the first: it hears nothing.
        $this->assertSent([
            $second->id => ['position' => null, 'stops_before' => null, 'status' => 'delivery_failed'],
            $third->id => ['position' => 2, 'stops_before' => 1, 'status' => 'picked_up'],
        ]);
    }

    public function test_parcels_whose_numbers_stay_the_same_hear_nothing()
    {
        $this->onTheVan(2);
        $toCollect = $this->job(3);
        Event::fake([DeliveryProgressUpdated::class]);

        // Collecting a parcel that comes after them changes nothing for the others.
        app(MarkPickedUp::class)->handle($toCollect, $this->driver);

        $this->assertSent([
            $toCollect->id => ['position' => 3, 'stops_before' => 2, 'status' => 'picked_up'],
        ]);
    }

    public function test_a_parcel_collected_ahead_of_others_moves_them_down()
    {
        [$first, $second] = $this->onTheVan(2, from: 2);
        $ahead = $this->job(1);
        Event::fake([DeliveryProgressUpdated::class]);

        app(MarkPickedUp::class)->handle($ahead, $this->driver);

        $this->assertSent([
            $ahead->id => ['position' => 1, 'stops_before' => 0, 'status' => 'picked_up'],
            $first->id => ['position' => 2, 'stops_before' => 1, 'status' => 'picked_up'],
            $second->id => ['position' => 3, 'stops_before' => 2, 'status' => 'picked_up'],
        ]);
    }

    public function test_a_delivery_handed_over_before_it_is_collected_moves_no_stop_on_either_van()
    {
        [$mine] = $this->onTheVan(1);
        $wong = User::factory()->driver()->create();
        $theirs = Order::factory()->assigned($wong)->create(['route_position' => 1]);
        app(MarkPickedUp::class)->handle($theirs, $wong);
        $handedOver = $this->job(2);
        Event::fake([DeliveryProgressUpdated::class]);

        app(AssignDriver::class)->handle($handedOver, User::factory()->admin()->create(), $wong, today('Asia/Kuala_Lumpur'));

        // Not collected yet, so no parcel on either van moved: only the
        // parcel itself hears it has no stop.
        $this->assertSent([
            $handedOver->id => ['position' => null, 'stops_before' => null, 'status' => 'assigned'],
        ]);
        $this->assertSame(['position' => 1, 'stops_before' => 0], app(DeliveryProgress::class)->forOrder($mine));
    }

    public function test_the_message_carries_no_personal_data_and_no_tracking_number()
    {
        Event::fake([DeliveryProgressUpdated::class]);
        $order = $this->job(1, [
            'tracking_number' => 'KT7Q4M92XD',
            'receiver_name' => 'Daniel Lim',
            'receiver_phone' => '+60127788990',
            'receiver_email' => 'daniel@example.com',
            'city' => 'Kuala Lumpur',
        ]);

        app(MarkPickedUp::class)->handle($order, $this->driver);

        Event::assertDispatched(DeliveryProgressUpdated::class, function (DeliveryProgressUpdated $event): bool {
            $wire = (string) json_encode([array_map(fn ($channel) => $channel->name, $event->broadcastOn()), $event->broadcastWith()]);

            $this->assertSame(['position', 'stops_before', 'status'], array_keys($event->broadcastWith()));

            foreach (['7Q4M92XD', 'Daniel', '60127788990', 'daniel@example.com', 'Kuala Lumpur', $this->driver->name, (string) $this->driver->vehicle_plate] as $secret) {
                $this->assertStringNotContainsString($secret, $wire);
            }

            return true;
        });
    }

    public function test_the_numbers_are_worked_out_and_sent_on_a_queue_of_their_own()
    {
        Queue::fake();
        $order = $this->job(1);

        app(MarkPickedUp::class)->handle($order, $this->driver);
        app(OrderStatusService::class)->transition(Order::factory()->create(), OrderStatus::DroppedOff, null);

        // Once, for the pick-up: steps before a driver has the parcel concern no run.
        $jobs = Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === SendDeliveryProgress::class);
        $this->assertCount(1, $jobs);
        $this->assertSame(3, $jobs->first()?->tries);
        $this->assertSame(5, $jobs->first()?->backoff);
        // The worker takes "live" before the emails on "default".
        Queue::assertPushedOn('live', CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === SendDeliveryProgress::class);
    }

    public function test_a_run_another_change_holds_is_worked_out_again_a_few_seconds_later()
    {
        Exceptions::fake();
        Event::fake([DeliveryProgressUpdated::class]);
        Sleep::fake(syncWithCarbon: true);
        config(['queue.default' => 'database']);
        $order = $this->job(1);
        $held = Cache::lock("delivery-progress:driver:{$this->driver->id}", 60);
        $this->assertTrue($held->get());

        app(MarkPickedUp::class)->handle($order, $this->driver);
        $this->artisan('queue:work', ['--once' => true, '--queue' => 'live'])->assertSuccessful();

        // It waited five seconds for the lock, then went back on the queue
        // for five more, without failing.
        Exceptions::assertReported(LockTimeoutException::class);
        Event::assertNotDispatched(DeliveryProgressUpdated::class);
        $job = DB::table('jobs')->where('queue', 'live')->sole();
        $this->assertSame(1, (int) $job->attempts);
        $this->assertSame(now()->addSeconds(5)->getTimestamp(), (int) $job->available_at);
        $this->assertSame(0, DB::table('failed_jobs')->count());

        $held->forceRelease();
        $this->travel(5)->seconds();
        $this->artisan('queue:work', ['--once' => true, '--queue' => 'live'])->assertSuccessful();

        $this->assertSent([
            $order->id => ['position' => 1, 'stops_before' => 0, 'status' => 'picked_up'],
        ]);
        $this->assertSame(0, DB::table('jobs')->where('queue', 'live')->count());
    }

    public function test_a_job_carried_over_and_moved_to_today_by_the_same_driver_works_out_one_run()
    {
        $broadcast = $this->spy(BroadcastDeliveryProgress::class);
        $overdue = $this->job(1, ['scheduled_for' => '2026-10-04']);
        $admin = User::factory()->admin()->create();

        // Yesterday's job was already on today's list, so only that run changed.
        app(AssignDriver::class)->handle($overdue, $admin, $this->driver, CarbonImmutable::parse('2026-10-05'));
        $broadcast->shouldHaveReceived('handle')->once();

        // Moved on to tomorrow, it joins that run and leaves today's.
        app(AssignDriver::class)->handle($overdue->refresh(), $admin, $this->driver, CarbonImmutable::parse('2026-10-06'));
        $broadcast->shouldHaveReceived('handle')->times(3);
    }

    public function test_a_failed_delivery_scheduled_again_hears_it_has_no_stop()
    {
        [$order] = $this->onTheVan(1);
        app(RecordDeliveryFailure::class)->handle($order, $this->driver, DeliveryFailureReason::RecipientUnavailable);
        Event::fake([DeliveryProgressUpdated::class]);

        app(AssignDriver::class)->handle($order->refresh(), User::factory()->admin()->create(), $this->driver, CarbonImmutable::parse('2026-10-06'));

        $this->assertSent([
            $order->id => ['position' => null, 'stops_before' => null, 'status' => 'assigned'],
        ]);
    }

    public function test_numbers_reverb_did_not_take_are_sent_again_the_next_time_the_run_is_worked_out()
    {
        Exceptions::fake();
        $order = $this->job(1);
        $this->job(2);
        $toMove = $this->job(3);
        $this->reverbIsDown();

        app(MarkPickedUp::class)->handle($order, $this->driver);
        Exceptions::assertReported(BroadcastException::class);

        // Reverb is back. Moving another stop changes nothing for the parcel
        // on the van, but its page never got its stop, so it is sent again.
        Event::fake([DeliveryProgressUpdated::class]);
        app(MoveJob::class)->handle($toMove, $this->driver, MoveDirection::Up);

        $this->assertSent([
            $order->id => ['position' => 1, 'stops_before' => 0, 'status' => 'picked_up'],
        ]);
    }

    public function test_reverb_being_down_never_stops_a_delivery()
    {
        Exceptions::fake();
        $this->reverbIsDown();
        $order = Order::factory()->pickedUp($this->driver)->create(['route_position' => 1]);

        $this->actingAs($this->driver)->post(route('driver.jobs.deliver', $order), [
            'recipient_name' => 'Daniel Lim',
            'photo' => UploadedFile::fake()->image('door.png'),
        ])->assertSessionHasNoErrors()->assertRedirect(route('driver.jobs'));

        $this->assertSame(OrderStatus::Delivered, $order->refresh()->status);
        Exceptions::assertReported(BroadcastException::class);
    }

    /**
     * Make every broadcast fail, as when Reverb is not running.
     */
    private function reverbIsDown(): void
    {
        Broadcast::extend('down', fn () => new class extends Broadcaster
        {
            public function auth($request): mixed
            {
                return null;
            }

            public function validAuthenticationResponse($request, $result): mixed
            {
                return null;
            }

            /**
             * @param  array<int, mixed>  $channels
             * @param  array<string, mixed>  $payload
             */
            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new BroadcastException('Reverb is not running.');
            }
        });
        config(['broadcasting.connections.down' => ['driver' => 'down'], 'broadcasting.default' => 'down']);
    }

    /**
     * Create the given number of jobs on the driver's run for today and pick
     * them up, as the driver would, so each has heard its stop.
     *
     * @return list<Order>
     */
    private function onTheVan(int $count, int $from = 1): array
    {
        return array_map(function (int $place): Order {
            $order = $this->job($place);
            app(MarkPickedUp::class)->handle($order, $this->driver);

            return $order->refresh();
        }, range($from, $from + $count - 1));
    }

    /**
     * Create a job of the driver's for today, still to collect, at the given place.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function job(int $place, array $attributes = []): Order
    {
        return Order::factory()->assigned($this->driver)->create(['route_position' => $place, 'postcode' => '50000', ...$attributes]);
    }

    /**
     * Assert that exactly these parcels were sent these numbers, each on its own channels.
     *
     * @param  array<int, array{position: int|null, stops_before: int|null, status: string}>  $expected  keyed by order id
     */
    private function assertSent(array $expected): void
    {
        $sent = Event::dispatched(DeliveryProgressUpdated::class)
            ->mapWithKeys(fn (array $call): array => [(int) substr($call[0]->privateChannel, strlen('orders.')) => $call[0]->progress])
            ->all();

        ksort($sent);
        ksort($expected);
        $this->assertSame($expected, $sent);

        foreach (Event::dispatched(DeliveryProgressUpdated::class) as [$event]) {
            $order = Order::query()->findOrFail((int) substr($event->privateChannel, strlen('orders.')));
            $this->assertSame(DeliveryProgress::publicChannel($order), $event->publicChannel);
        }
    }
}
