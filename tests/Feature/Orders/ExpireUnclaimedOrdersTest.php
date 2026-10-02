<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\ExpireUnclaimedOrders;
use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpireUnclaimedOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_never_dropped_off_are_cancelled_after_the_configured_days()
    {
        $stale = Order::factory()->create(['created_at' => now()->subDays(15)]);
        $recent = Order::factory()->create(['created_at' => now()->subDays(13)]);
        $droppedOff = Order::factory()->droppedOff()->create(['created_at' => now()->subDays(30)]);

        $cancelled = app(ExpireUnclaimedOrders::class)->handle();

        $this->assertSame(1, $cancelled);
        $this->assertSame(OrderStatus::Cancelled, $stale->fresh()?->status);
        $this->assertSame('Not dropped off within 14 days.', $stale->latestStatusEvent()->firstOrFail()->note);
        $this->assertNull($stale->latestStatusEvent()->firstOrFail()->actor_id);
        $this->assertSame(OrderStatus::Created, $recent->fresh()?->status);
        $this->assertSame(OrderStatus::DroppedOff, $droppedOff->fresh()?->status);
    }

    public function test_the_command_runs_the_action_and_is_scheduled_daily()
    {
        Order::factory()->create(['created_at' => now()->subDays(20)]);

        $this->artisan('orders:expire-unclaimed')
            ->expectsOutput('Cancelled 1 unclaimed order(s).')
            ->assertSuccessful();

        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'orders:expire-unclaimed'));

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
    }
}
