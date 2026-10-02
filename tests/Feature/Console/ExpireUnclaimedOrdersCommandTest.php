<?php

namespace Tests\Feature\Console;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Notifications\OrderStatusUpdated;
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

    public function test_only_created_orders_older_than_the_limit_are_cancelled()
    {
        $stale = Order::factory()->create(['created_at' => now()->subDays(20)]);
        $exactlyAtLimit = Order::factory()->create(['created_at' => now()->subDays(14)]);
        $recent = Order::factory()->create(['created_at' => now()->subDays(14)->addMinute()]);
        $others = collect([
            Order::factory()->droppedOff()->create(['created_at' => now()->subDays(30)]),
            Order::factory()->paid()->create(['created_at' => now()->subDays(30)]),
            Order::factory()->delivered()->create(['created_at' => now()->subDays(30)]),
            Order::factory()->cancelled()->create(['created_at' => now()->subDays(30)]),
        ]);

        $this->artisan('orders:expire-unclaimed')
            ->expectsOutput('Cancelled 2 unclaimed order(s).')
            ->assertSuccessful();

        foreach ([$stale, $exactlyAtLimit] as $order) {
            $order->refresh();
            $this->assertSame(OrderStatus::Cancelled, $order->status);
            $this->assertNotNull($order->cancelled_at);
            $this->assertSame('Not dropped off within 14 days.', $order->latestStatusEvent()->firstOrFail()->note);
        }

        $this->assertSame(OrderStatus::Created, $recent->fresh()?->status);
        $others->each(fn (Order $order) => $this->assertSame($order->status, $order->fresh()?->status));
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

    public function test_the_limit_comes_from_config()
    {
        config(['kotak.unclaimed_order_days' => 7]);
        $order = Order::factory()->create(['created_at' => now()->subDays(8)]);

        $this->artisan('orders:expire-unclaimed')
            ->expectsOutput('Cancelled 1 unclaimed order(s).')
            ->assertSuccessful();

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
}
