<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\ExpireUnclaimedOrders;
use App\Enums\OrderStatus;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpireUnclaimedOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_never_dropped_off_are_cancelled_once_their_deadline_day_has_ended()
    {
        // 10:00 on 10 October in Kuala Lumpur. 7 days to drop off.
        $this->travelTo(CarbonImmutable::parse('2026-10-10 02:00:00', 'UTC'));

        $stale = Order::factory()->create(['created_at' => now()->subDays(8)]);
        $deadlineToday = Order::factory()->create(['created_at' => now()->subDays(7)->startOfDay()]);
        $droppedOff = Order::factory()->droppedOff()->create(['created_at' => now()->subDays(30)]);

        $cancelled = app(ExpireUnclaimedOrders::class)->handle();

        $this->assertSame(1, $cancelled);
        $this->assertSame(OrderStatus::Cancelled, $stale->fresh()?->status);
        $this->assertSame('Not dropped off by 9 October 2026.', $stale->latestStatusEvent()->firstOrFail()->note);
        $this->assertNull($stale->latestStatusEvent()->firstOrFail()->actor_id);
        $this->assertSame(OrderStatus::Created, $deadlineToday->fresh()?->status);
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
