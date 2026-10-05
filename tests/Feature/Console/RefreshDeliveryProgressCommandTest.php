<?php

namespace Tests\Feature\Console;

use App\Actions\Delivery\BroadcastDeliveryProgress;
use App\Actions\Delivery\MoveJob;
use App\Enums\MoveDirection;
use App\Events\DeliveryProgressUpdated;
use App\Models\Order;
use App\Models\User;
use App\Support\DeliveryProgress;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class RefreshDeliveryProgressCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $ravi;

    private User $siti;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 21:00', 'Asia/Kuala_Lumpur'));
        $this->ravi = User::factory()->driver()->create();
        $this->siti = User::factory()->driver()->create();
    }

    public function test_after_midnight_only_the_parcels_whose_stop_changed_hear_it()
    {
        // Left on Ravi's van tonight, and two parcels he collected early for tomorrow.
        $leftOver = $this->onTheVan($this->ravi, '2026-10-05', 1);
        $early = $this->onTheVan($this->ravi, '2026-10-06', 1);
        $earlyToo = $this->onTheVan($this->ravi, '2026-10-06', 2);
        // Nothing comes before Siti's parcel for tomorrow, and Wong has nothing on his van.
        $this->onTheVan($this->siti, '2026-10-06', 1);
        Order::factory()->assigned(User::factory()->driver()->create())->create(['scheduled_for' => '2026-10-05', 'route_position' => 1]);
        $this->eachPageHeardItsStop();

        $this->travelTo(CarbonImmutable::parse('2026-10-06 00:01', 'Asia/Kuala_Lumpur'));
        Event::fake([DeliveryProgressUpdated::class]);

        $this->artisan('deliveries:refresh-progress')
            ->expectsOutput('Refreshed the stops of 2 delivery run(s).')
            ->assertSuccessful();

        // Yesterday's parcel now comes first on today's list, so the two
        // behind it each have one more stop before them. Nobody else hears a thing.
        $this->assertSame([
            "orders.{$early->id}" => ['position' => 2, 'stops_before' => 1, 'status' => 'picked_up'],
            "orders.{$earlyToo->id}" => ['position' => 3, 'stops_before' => 2, 'status' => 'picked_up'],
        ], $this->sent());
        // It only works the counts out: the parcel stays on yesterday's run
        // until the driver moves a stop.
        $leftOver->refresh();
        $this->assertSame([1, '2026-10-05'], [$leftOver->route_position, $leftOver->route_date?->toDateString()]);

        // A second run finds nothing new to send.
        $this->artisan('deliveries:refresh-progress')->assertSuccessful();
        Event::assertDispatchedTimes(DeliveryProgressUpdated::class, 2);
    }

    public function test_a_parcel_carried_over_that_the_driver_left_until_last_stays_last_after_midnight()
    {
        // Ravi still has Sunday's parcel on his van and two for today, and
        // leaves Sunday's until last.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00', 'Asia/Kuala_Lumpur'));
        $leftOver = $this->onTheVan($this->ravi, '2026-10-04', 1);
        $first = $this->onTheVan($this->ravi, '2026-10-05', 1);
        $second = $this->onTheVan($this->ravi, '2026-10-05', 2);
        $moveJob = app(MoveJob::class);
        $moveJob->handle($leftOver, $this->ravi, MoveDirection::Down);
        $moveJob->handle($leftOver->refresh(), $this->ravi, MoveDirection::Down);
        $this->eachPageHeardItsStop();
        $this->assertSame(['position' => 3, 'stops_before' => 2], app(DeliveryProgress::class)->forOrder($leftOver->refresh()));

        $this->travelTo(CarbonImmutable::parse('2026-10-06 00:01', 'Asia/Kuala_Lumpur'));
        Event::fake([DeliveryProgressUpdated::class]);

        $this->artisan('deliveries:refresh-progress')
            ->expectsOutput('Refreshed the stops of 1 delivery run(s).')
            ->assertSuccessful();

        // All three are carried over in the order he left them, so nobody's
        // stop changed and nobody hears a thing.
        Event::assertNotDispatched(DeliveryProgressUpdated::class);
        $this->assertSame(
            [$first->id, $second->id, $leftOver->id],
            Order::query()->jobListFor($this->ravi, today('Asia/Kuala_Lumpur'))->pluck('id')->all(),
        );
        $this->assertSame(['position' => 3, 'stops_before' => 2], app(DeliveryProgress::class)->forOrder($leftOver->refresh()));
    }

    public function test_a_run_that_cannot_be_worked_out_is_reported_and_the_others_still_are()
    {
        Exceptions::fake();
        Sleep::fake(syncWithCarbon: true);
        $this->onTheVan($this->ravi, '2026-10-05', 1);
        $this->onTheVan($this->ravi, '2026-10-06', 1);
        $this->onTheVan($this->siti, '2026-10-05', 1);
        $sitis = $this->onTheVan($this->siti, '2026-10-06', 1);
        $this->eachPageHeardItsStop();

        $this->travelTo(CarbonImmutable::parse('2026-10-06 00:01', 'Asia/Kuala_Lumpur'));
        Event::fake([DeliveryProgressUpdated::class]);
        $held = Cache::lock("delivery-progress:driver:{$this->ravi->id}", 60);
        $this->assertTrue($held->get());

        $this->artisan('deliveries:refresh-progress')
            ->expectsOutput('Refreshed the stops of 1 delivery run(s).')
            ->assertSuccessful();

        Exceptions::assertReported(LockTimeoutException::class);
        $this->assertSame([
            "orders.{$sitis->id}" => ['position' => 2, 'stops_before' => 1, 'status' => 'picked_up'],
        ], $this->sent());
        $held->release();
    }

    public function test_it_runs_a_minute_after_midnight_malaysia_time()
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'deliveries:refresh-progress'));

        $this->assertInstanceOf(ScheduledEvent::class, $event);
        $this->assertSame('1 0 * * *', $event->expression);
        $this->assertSame('Asia/Kuala_Lumpur', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_the_schedule_lists_it()
    {
        Artisan::call('schedule:list');

        $this->assertStringContainsString('deliveries:refresh-progress', Artisan::output());
    }

    /**
     * Create a parcel on the driver's van at a place on the run for its day.
     */
    private function onTheVan(User $driver, string $scheduledFor, int $position): Order
    {
        return Order::factory()->pickedUp($driver)->create([
            'scheduled_for' => $scheduledFor,
            'route_position' => $position,
            'postcode' => '50000',
        ]);
    }

    /**
     * Send every run's stops, as the pick-ups did during the day.
     */
    private function eachPageHeardItsStop(): void
    {
        $broadcast = app(BroadcastDeliveryProgress::class);

        foreach ([$this->ravi, $this->siti] as $driver) {
            foreach (['2026-10-05', '2026-10-06'] as $day) {
                $broadcast->handle($driver, CarbonImmutable::parse($day));
            }
        }
    }

    /**
     * Get the numbers sent, keyed by each parcel's private channel.
     *
     * @return array<string, array{position: int|null, stops_before: int|null, status: string}>
     */
    private function sent(): array
    {
        return Event::dispatched(DeliveryProgressUpdated::class)
            ->mapWithKeys(fn (array $call): array => [$call[0]->privateChannel => $call[0]->progress])
            ->all();
    }
}
