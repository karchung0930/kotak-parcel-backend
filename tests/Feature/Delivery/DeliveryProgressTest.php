<?php

namespace Tests\Feature\Delivery;

use App\Actions\Delivery\AssignDriver;
use App\Actions\Delivery\MarkPickedUp;
use App\Actions\Delivery\RecordDeliveryFailure;
use App\Actions\Delivery\RecordDeliverySuccess;
use App\Enums\DeliveryFailureReason;
use App\Models\Order;
use App\Models\User;
use App\Support\DeliveryProgress;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeliveryProgressTest extends TestCase
{
    use RefreshDatabase;

    private DeliveryProgress $progress;

    private User $driver;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Kuala_Lumpur'));
        $this->today = CarbonImmutable::parse('2026-10-05', 'Asia/Kuala_Lumpur');
        $this->progress = app(DeliveryProgress::class);
        $this->driver = User::factory()->driver()->create();
    }

    public function test_stops_follow_the_drivers_order_and_count_only_parcels_on_the_van()
    {
        $first = $this->pickedUp(1, '59000');
        $toCollect = $this->assigned(2, '10000');
        $second = $this->pickedUp(3, '10000');
        $third = $this->pickedUp(4, '47300');

        $this->assertSame([
            $first->id => ['position' => 1, 'stops_before' => 0],
            $second->id => ['position' => 2, 'stops_before' => 1],
            $third->id => ['position' => 3, 'stops_before' => 2],
        ], $this->progress->forRun($this->driver, $this->today));

        // A parcel still to collect is not delivered before anything else.
        $this->assertNull($this->progress->forOrder($toCollect));
        $this->assertSame(['position' => 3, 'stops_before' => 2], $this->progress->forOrder($third));
    }

    public function test_stops_in_the_same_place_follow_the_postcode_then_the_order_they_were_made_in()
    {
        $madeFirst = $this->pickedUp(1, '50000');
        $madeSecond = $this->pickedUp(1, '50000');
        $nearer = $this->pickedUp(1, '46000');

        $this->assertSame(
            [$nearer->id, $madeFirst->id, $madeSecond->id],
            array_keys($this->progress->forRun($this->driver, $this->today)),
        );
    }

    public function test_delivered_and_failed_parcels_leave_the_count()
    {
        Storage::fake('local');
        $first = $this->pickedUp(1);
        $second = $this->pickedUp(2);
        $third = $this->pickedUp(3);
        $fourth = $this->pickedUp(4);

        app(RecordDeliverySuccess::class)->handle($first, $this->driver, 'Daniel Lim', UploadedFile::fake()->image('door.png'));
        app(RecordDeliveryFailure::class)->handle($third, $this->driver, DeliveryFailureReason::RecipientUnavailable);

        $this->assertSame([
            $second->id => ['position' => 1, 'stops_before' => 0],
            $fourth->id => ['position' => 2, 'stops_before' => 1],
        ], $this->progress->forRun($this->driver, $this->today));
        foreach ([$first->refresh(), $third->refresh()] as $left) {
            $this->assertNull($left->route_position);
            $this->assertNull($left->route_date);
        }
        $this->assertNull($this->progress->forOrder($first));
    }

    public function test_jobs_carried_over_from_earlier_days_come_first_on_todays_list()
    {
        $today = $this->pickedUp(1);
        $yesterday = $this->pickedUp(5, scheduledFor: '2026-10-04');

        $this->assertSame([
            $yesterday->id => ['position' => 1, 'stops_before' => 0],
            $today->id => ['position' => 2, 'stops_before' => 1],
        ], $this->progress->forRun($this->driver, $this->today));
        $this->assertSame(['position' => 1, 'stops_before' => 0], $this->progress->forOrder($yesterday));
    }

    public function test_other_days_and_other_drivers_do_not_count()
    {
        $mine = $this->pickedUp(2);
        Order::factory()->pickedUp()->create(['route_position' => 1, 'scheduled_for' => '2026-10-05']);
        $tomorrow = $this->pickedUp(1, scheduledFor: '2026-10-06');

        $this->assertSame(['position' => 1, 'stops_before' => 0], $this->progress->forOrder($mine));
        // Collected early, it is the first stop of tomorrow's list.
        $this->assertSame(['position' => 1, 'stops_before' => 0], $this->progress->forOrder($tomorrow));
        $this->assertSame([$tomorrow->id], array_keys($this->progress->forRun($this->driver, CarbonImmutable::parse('2026-10-06'))));
    }

    public function test_a_new_assignment_joins_the_end_of_the_run_and_a_reassignment_leaves_it()
    {
        $admin = User::factory()->admin()->create();
        $wong = User::factory()->driver()->create();
        $onTheVan = $this->pickedUp(1);
        $assign = app(AssignDriver::class);

        // A nearer postcode does not jump the queue: the driver decides the order.
        $added = $assign->handle(Order::factory()->paid()->create(['postcode' => '10000']), $admin, $this->driver, $this->today);
        $this->assertSame(2, $added->route_position);

        app(MarkPickedUp::class)->handle($added, $this->driver);
        $this->assertSame(['position' => 2, 'stops_before' => 1], $this->progress->forOrder($added->refresh()));

        // Handed to Wong before it is collected: it goes to the end of his run.
        $other = $assign->handle(Order::factory()->paid()->create(), $admin, $this->driver, $this->today);
        Order::factory()->assigned($wong)->create(['route_position' => 4, 'scheduled_for' => '2026-10-05']);
        $other = $assign->handle($other, $admin, $wong, $this->today);

        $this->assertSame(5, $other->route_position);
        $this->assertSame([$onTheVan->id, $added->id], array_keys($this->progress->forRun($this->driver, $this->today)));
        // Moved to another day of the same driver, it joins that day's run.
        $other = $assign->handle($other, $admin, $this->driver, $this->today->addDay());
        $this->assertSame([1, '2026-10-06'], [$other->route_position, $other->route_date?->toDateString()]);
    }

    public function test_a_delivery_assigned_for_today_goes_after_every_stop_on_todays_run()
    {
        $admin = User::factory()->admin()->create();
        // Carried over and not yet put among today's stops: its old place
        // belongs to yesterday's run.
        $leftOver = $this->pickedUp(7, scheduledFor: '2026-10-04');
        $first = $this->pickedUp(1);
        $second = $this->assigned(2);
        // Carried over and put last on today's run by the driver.
        $placed = $this->pickedUp(3, scheduledFor: '2026-10-03', routeDate: '2026-10-05');

        $added = app(AssignDriver::class)->handle(Order::factory()->paid()->create(['postcode' => '10000']), $admin, $this->driver, $this->today);

        $this->assertSame([4, '2026-10-05'], [$added->route_position, $added->route_date?->toDateString()]);
        $this->assertSame(
            [$leftOver->id, $first->id, $second->id, $placed->id, $added->id],
            Order::query()->jobListFor($this->driver, $this->today)->pluck('id')->all(),
        );

        app(MarkPickedUp::class)->handle($added, $this->driver);
        $this->assertSame(['position' => 4, 'stops_before' => 3], $this->progress->forOrder($added->refresh()));
    }

    public function test_the_stops_follow_the_list_with_carried_over_jobs_among_todays()
    {
        // Not yet on today's run: first, by the run each was on, then by place.
        $sunday = $this->pickedUp(1, scheduledFor: '2026-10-04');
        $saturday = $this->pickedUp(4, scheduledFor: '2026-10-03');
        // Today's run, with a job from Saturday the driver put between two of today's stops.
        $third = $this->pickedUp(3);
        $first = $this->pickedUp(1);
        $placed = $this->pickedUp(2, scheduledFor: '2026-10-03', routeDate: '2026-10-05');

        $this->assertSame([
            $saturday->id => ['position' => 1, 'stops_before' => 0],
            $sunday->id => ['position' => 2, 'stops_before' => 1],
            $first->id => ['position' => 3, 'stops_before' => 2],
            $placed->id => ['position' => 4, 'stops_before' => 3],
            $third->id => ['position' => 5, 'stops_before' => 4],
        ], $this->progress->forRun($this->driver, $this->today));
        $this->assertSame(['position' => 4, 'stops_before' => 3], $this->progress->forOrder($placed));
    }

    public function test_only_a_parcel_out_for_delivery_has_a_stop()
    {
        $this->assertNull($this->progress->forOrder($this->assigned(1)));
        $this->assertNull($this->progress->forOrder(Order::factory()->delivered($this->driver)->create()));
        $this->assertNull($this->progress->forOrder(Order::factory()->paid()->create()));
    }

    public function test_the_public_channel_cannot_be_worked_out_from_the_tracking_number()
    {
        $order = Order::factory()->create(['tracking_number' => 'KT7Q4M92XD']);
        $other = Order::factory()->create(['tracking_number' => 'KT7Q4M92XE']);

        $channel = DeliveryProgress::publicChannel($order);

        $this->assertMatchesRegularExpression('/^tracking\.[0-9a-f]{64}$/', $channel);
        $this->assertStringNotContainsStringIgnoringCase('7Q4M92XD', $channel);
        $this->assertSame($channel, DeliveryProgress::publicChannel($order->refresh()));
        $this->assertNotSame($channel, DeliveryProgress::publicChannel($other));
        $this->assertNotSame('tracking.'.hash('sha256', 'KT7Q4M92XD'), $channel);

        // It depends on the app key, which only the server knows.
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->assertNotSame($channel, DeliveryProgress::publicChannel($order));
        $this->assertSame("orders.{$order->id}", DeliveryProgress::privateChannel($order));
    }

    public function test_the_migration_numbers_open_runs_in_the_order_my_jobs_listed_them()
    {
        $ravi = $this->driver;
        $siti = User::factory()->driver()->create();
        $a = Order::factory()->assigned($ravi)->create(['postcode' => '59000']);
        $b = Order::factory()->pickedUp($ravi)->create(['postcode' => '47300']);
        $c = Order::factory()->assigned($ravi)->create(['postcode' => '50450', 'scheduled_for' => '2026-10-06']);
        $d = Order::factory()->assigned($siti)->create(['postcode' => '60000']);
        $delivered = Order::factory()->delivered($ravi)->create();

        $migration = require database_path('migrations/2026_10_05_000002_add_route_position_to_orders_table.php');
        $this->assertInstanceOf(Migration::class, $migration);
        $migration->numberOpenRuns();

        // By postcode within each driver's day; finished jobs are on no run.
        $this->assertSame(
            [1, 2, 1, 1, null],
            [$b->refresh()->route_position, $a->refresh()->route_position, $c->refresh()->route_position, $d->refresh()->route_position, $delivered->refresh()->route_position],
        );
    }

    public function test_the_migration_puts_the_open_stops_on_their_scheduled_days_run()
    {
        $today = Order::factory()->assigned($this->driver)->create(['route_position' => 2, 'route_date' => null]);
        $overdue = Order::factory()->pickedUp($this->driver)->create(['route_position' => 1, 'route_date' => null, 'scheduled_for' => '2026-10-04']);
        // Another driver's job that never had a place, and a finished one
        // still holding its old place.
        $placeless = Order::factory()->assigned()->create(['route_position' => null]);
        $delivered = Order::factory()->delivered($this->driver)->create(['route_position' => 3, 'route_date' => null]);

        $migration = require database_path('migrations/2026_10_05_000003_add_route_date_to_orders_table.php');
        $this->assertInstanceOf(Migration::class, $migration);
        $migration->dateOpenRuns();

        // Until now a run was its scheduled day, so today's list keeps its order.
        $this->assertSame(
            ['2026-10-05', '2026-10-04', null, null],
            [$today->refresh()->route_date?->toDateString(), $overdue->refresh()->route_date?->toDateString(), $placeless->refresh()->route_date, $delivered->refresh()->route_date],
        );
        $this->assertSame([$overdue->id, $today->id], Order::query()->jobListFor($this->driver, $this->today)->pluck('id')->all());
    }

    /**
     * Create a job on the driver's run that is still to be collected.
     */
    private function assigned(int $position, string $postcode = '50000', string $scheduledFor = '2026-10-05', ?string $routeDate = null): Order
    {
        return Order::factory()->assigned($this->driver)->create([
            'route_position' => $position,
            'postcode' => $postcode,
            'scheduled_for' => $scheduledFor,
            'route_date' => $routeDate ?? $scheduledFor,
        ]);
    }

    /**
     * Create a job on the driver's run that is on the van, placed on its
     * scheduled day's run unless given another day.
     */
    private function pickedUp(int $position, string $postcode = '50000', string $scheduledFor = '2026-10-05', ?string $routeDate = null): Order
    {
        return Order::factory()->pickedUp($this->driver)->create([
            'route_position' => $position,
            'postcode' => $postcode,
            'scheduled_for' => $scheduledFor,
            'route_date' => $routeDate ?? $scheduledFor,
        ]);
    }
}
