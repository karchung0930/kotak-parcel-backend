<?php

namespace Tests\Feature\Console;

use App\Actions\Settings\UpdateSettings;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderStatusUpdated;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ExpireUnclaimedOrdersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->freezeSecond();
    }

    public function test_only_created_orders_past_their_deadline_day_are_cancelled()
    {
        $stale = $this->orderPlacedAt('2026-09-20 10:00:00');
        // 7 days to drop off: the deadline is 7 October, so tonight's run cancels it.
        $deadlineYesterday = $this->orderPlacedAt('2026-09-30 23:59:00');
        // The deadline is today, 8 October: kept until tonight however early it was placed.
        $deadlineToday = $this->orderPlacedAt('2026-10-01 00:00:10');
        $others = collect([
            Order::factory()->droppedOff()->create(['created_at' => now()->subDays(30)]),
            Order::factory()->paid()->create(['created_at' => now()->subDays(30)]),
            Order::factory()->delivered()->create(['created_at' => now()->subDays(30)]),
            Order::factory()->cancelled()->create(['created_at' => now()->subDays(30)]),
        ]);

        // The midnight run that starts 8 October.
        $this->runAt('2026-10-08 00:00:30');

        $this->artisan('orders:expire-unclaimed')
            ->expectsOutput('Cancelled 2 unclaimed order(s).')
            ->assertSuccessful();

        $this->assertSame('Not dropped off by 27 September 2026.', $stale->latestStatusEvent()->firstOrFail()->note);
        $this->assertSame('Not dropped off by 7 October 2026.', $deadlineYesterday->latestStatusEvent()->firstOrFail()->note);

        foreach ([$stale, $deadlineYesterday] as $order) {
            $order->refresh();
            $this->assertSame(OrderStatus::Cancelled, $order->status);
            $this->assertNotNull($order->cancelled_at);
        }

        $this->assertSame(OrderStatus::Created, $deadlineToday->fresh()?->status);
        $others->each(fn (Order $order) => $this->assertSame($order->status, $order->fresh()?->status));
    }

    public function test_no_order_is_cancelled_before_the_day_after_its_deadline()
    {
        $orders = collect(['00:00:10', '08:00:00', '22:00:00'])
            ->map(fn (string $time) => $this->orderPlacedAt("2026-10-01 {$time}"));

        // On the deadline day, 8 October: neither the midnight run nor a run started by hand.
        foreach (['2026-10-08 00:00:30', '2026-10-08 15:00:00', '2026-10-08 23:59:59'] as $time) {
            $this->runAt($time);
            $this->artisan('orders:expire-unclaimed')->expectsOutput('Cancelled 0 unclaimed order(s).');
        }

        // The run at midnight that ends it cancels all three.
        $this->runAt('2026-10-09 00:00:30');
        $this->artisan('orders:expire-unclaimed')->expectsOutput('Cancelled 3 unclaimed order(s).');

        $orders->each(fn (Order $order) => $this->assertSame(OrderStatus::Cancelled, $order->fresh()?->status));
    }

    public function test_customers_are_told_their_order_expired()
    {
        $stale = Order::factory()->create(['created_at' => now()->subDays(20)]);
        $recent = Order::factory()->create();

        $this->artisan('orders:expire-unclaimed')->assertSuccessful();

        Notification::assertSentTo(
            $stale->customer,
            OrderStatusUpdated::class,
            fn (OrderStatusUpdated $notification) => $notification->status === OrderStatus::Cancelled,
        );
        Notification::assertNotSentTo($recent->customer, OrderStatusUpdated::class);
    }

    public function test_the_default_limit_comes_from_config()
    {
        config(['kotak.unclaimed_order_days' => 5]);
        $order = Order::factory()->create(['created_at' => now()->subDays(6)]);

        $this->artisan('orders:expire-unclaimed')
            ->expectsOutput('Cancelled 1 unclaimed order(s).')
            ->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()?->status);
    }

    public function test_orders_placed_after_an_admin_saves_a_limit_get_it()
    {
        $this->runAt('2026-10-01 10:00:00');
        app(UpdateSettings::class)->handle(User::factory()->admin()->create(), ['unclaimed_order_days' => 10]);
        $order = Order::factory()->create();

        $this->assertSame('2026-10-11', $order->dropOffDeadline()?->toDateString());

        $this->runAt('2026-10-11 15:00:00');
        $this->artisan('orders:expire-unclaimed')->expectsOutput('Cancelled 0 unclaimed order(s).');

        $this->runAt('2026-10-12 00:00:30');
        $this->artisan('orders:expire-unclaimed')->expectsOutput('Cancelled 1 unclaimed order(s).');

        $this->assertSame('Not dropped off by 11 October 2026.', $order->latestStatusEvent()->firstOrFail()->note);
    }

    public function test_a_new_limit_never_moves_the_deadline_of_orders_already_waiting()
    {
        $admin = User::factory()->admin()->create();
        // Placed on 1 October with 7 days: the customer is told 8 October.
        $order = $this->orderPlacedAt('2026-10-01 10:00:00');

        // A shorter limit would have ended it on 4 October.
        app(UpdateSettings::class)->handle($admin, ['unclaimed_order_days' => 3, 'drop_off_reminder_days_before' => 1]);
        $this->runAt('2026-10-05 00:00:30');
        $this->artisan('orders:expire-unclaimed')->expectsOutput('Cancelled 0 unclaimed order(s).');

        // A longer one does not stretch it either.
        app(UpdateSettings::class)->handle($admin, ['unclaimed_order_days' => 30]);
        $this->runAt('2026-10-09 00:00:30');
        $this->artisan('orders:expire-unclaimed')->expectsOutput('Cancelled 1 unclaimed order(s).');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()?->status);
    }

    public function test_running_again_changes_nothing()
    {
        Order::factory()->create(['created_at' => now()->subDays(20)]);

        $this->artisan('orders:expire-unclaimed')->expectsOutput('Cancelled 1 unclaimed order(s).');
        $this->artisan('orders:expire-unclaimed')->expectsOutput('Cancelled 0 unclaimed order(s).');
    }

    public function test_it_runs_every_night_at_midnight_malaysia_time()
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'orders:expire-unclaimed'));

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame('0 0 * * *', $event->expression);
        $this->assertSame('Asia/Kuala_Lumpur', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }

    /**
     * Place an order at the given Kuala Lumpur time, with the current limit.
     */
    private function orderPlacedAt(string $localTime): Order
    {
        $this->runAt($localTime);

        return Order::factory()->create();
    }

    /**
     * Move the clock to the given Kuala Lumpur time.
     */
    private function runAt(string $localTime): void
    {
        $this->travelTo(CarbonImmutable::parse($localTime, 'Asia/Kuala_Lumpur')->utc());
    }
}
