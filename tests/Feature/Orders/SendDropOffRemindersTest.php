<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\SendDropOffReminders;
use App\Actions\Settings\UpdateSettings;
use App\Models\Order;
use App\Models\User;
use App\Notifications\DropOffReminder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendDropOffRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();
    }

    public function test_the_reminder_window_runs_from_two_days_before_the_deadline_to_the_deadline_day()
    {
        Notification::fake();

        // Placed on 1 October (Kuala Lumpur), 7 days to drop off: the deadline is 8 October.
        $tooLate = $this->orderPlacedAt('2026-09-30 23:59:00');
        $deadlineDay = $this->orderPlacedAt('2026-10-01 10:00:00');
        $oneDayLeft = $this->orderPlacedAt('2026-10-02 10:00:00');
        $twoDaysLeft = $this->orderPlacedAt('2026-10-03 23:59:00');
        $tooEarly = $this->orderPlacedAt('2026-10-04 00:00:10');

        $this->runAt('2026-10-08 09:00:00');

        $this->assertSame(3, app(SendDropOffReminders::class)->handle());

        Notification::assertSentTo($twoDaysLeft->customer, DropOffReminder::class, fn (DropOffReminder $reminder) => $reminder->order->is($twoDaysLeft));
        Notification::assertSentTo([$oneDayLeft->customer, $deadlineDay->customer], DropOffReminder::class);
        Notification::assertNotSentTo([$tooEarly->customer, $tooLate->customer], DropOffReminder::class);

        $this->assertEquals(now(), $twoDaysLeft->fresh()?->drop_off_reminded_at);
        $this->assertNull($tooEarly->fresh()?->drop_off_reminded_at);
        $this->assertNull($tooLate->fresh()?->drop_off_reminded_at);
    }

    public function test_orders_placed_on_the_same_day_are_reminded_on_the_same_morning_whatever_the_hour()
    {
        Notification::fake();

        $orders = collect(['00:00:10', '08:00:00', '22:00:00'])
            ->map(fn (string $time) => $this->orderPlacedAt("2026-10-01 {$time}"));

        $orders->each(fn (Order $order) => $this->assertSame('2026-10-08', $order->dropOffDeadline()?->toDateString()));

        // The 9:00 run three days before the deadline: nobody yet.
        $this->runAt('2026-10-05 09:00:00');
        $this->assertSame(0, app(SendDropOffReminders::class)->handle());

        // Two days before: all three, exactly the lead time before their deadline.
        $this->runAt('2026-10-06 09:00:00');
        $this->assertSame(3, app(SendDropOffReminders::class)->handle());

        $orders->each(function (Order $order) {
            $order->refresh();
            $this->assertSame(
                $order->dropOffDeadline()?->subDays(2)->toDateString(),
                $order->drop_off_reminded_at?->setTimezone('Asia/Kuala_Lumpur')->toDateString(),
            );
        });
    }

    public function test_an_order_is_reminded_up_to_its_deadline_day()
    {
        Notification::fake();

        // Placed while reminders were off, then turned on with one day's notice.
        app(UpdateSettings::class)->handle(User::factory()->admin()->create(), ['drop_off_reminder_days_before' => 0]);
        $order = $this->orderPlacedAt('2026-10-01 08:00:00');
        app(UpdateSettings::class)->handle(User::factory()->admin()->create(), ['drop_off_reminder_days_before' => 1]);

        // The 9:00 run on the deadline day still reminds the customer.
        $this->runAt('2026-10-08 09:00:00');
        $this->assertSame(1, app(SendDropOffReminders::class)->handle());
        Notification::assertSentTo($order->customer, DropOffReminder::class);
    }

    public function test_only_orders_waiting_for_drop_off_are_reminded()
    {
        Notification::fake();

        $orders = collect([
            Order::factory()->droppedOff()->create(['created_at' => now()->subDays(6)]),
            Order::factory()->paid()->create(['created_at' => now()->subDays(6)]),
            Order::factory()->cancelled()->create(['created_at' => now()->subDays(6)]),
        ]);

        $this->assertSame(0, app(SendDropOffReminders::class)->handle());

        Notification::assertNothingSent();
        $orders->each(fn (Order $order) => $this->assertNull($order->fresh()?->drop_off_reminded_at));
    }

    public function test_nobody_is_reminded_twice()
    {
        Notification::fake();
        $order = Order::factory()->create(['created_at' => now()->subDays(6)]);

        $this->assertSame(1, app(SendDropOffReminders::class)->handle());
        $this->assertSame(0, app(SendDropOffReminders::class)->handle());

        // Still not twice when the reminder is moved earlier and the order is still in the window.
        app(UpdateSettings::class)->handle(User::factory()->admin()->create(), ['drop_off_reminder_days_before' => 4]);
        $this->assertSame(0, app(SendDropOffReminders::class)->handle());

        Notification::assertSentToTimes($order->customer, DropOffReminder::class, 1);
    }

    public function test_an_order_reminded_by_an_overlapping_run_is_skipped()
    {
        Notification::fake();
        $order = Order::factory()->create(['created_at' => now()->subDays(6)]);

        // Another run marks the order after this run found it, before this run locks the row.
        $marked = false;
        Order::retrieved(function (Order $retrieved) use ($order, &$marked) {
            if (! $marked && $retrieved->is($order)) {
                $marked = true;
                Order::query()->whereKey($order->id)->update(['drop_off_reminded_at' => now()->subMinute()]);
            }
        });

        $this->assertSame(0, app(SendDropOffReminders::class)->handle());

        $this->assertTrue($marked);
        Notification::assertNothingSent();
    }

    public function test_customers_without_a_verified_email_are_marked_but_not_emailed()
    {
        Notification::fake();
        $order = Order::factory()->for(User::factory()->unverified(), 'customer')->create(['created_at' => now()->subDays(6)]);

        $this->assertSame(0, app(SendDropOffReminders::class)->handle());

        Notification::assertNothingSent();
        $this->assertNotNull($order->fresh()?->drop_off_reminded_at);
    }

    public function test_deactivated_customers_are_marked_but_not_emailed()
    {
        Notification::fake();
        $order = Order::factory()->for(User::factory()->inactive(), 'customer')->create(['created_at' => now()->subDays(6)]);

        $this->assertSame(0, app(SendDropOffReminders::class)->handle());

        Notification::assertNothingSent();
        $this->assertNotNull($order->fresh()?->drop_off_reminded_at);
    }

    public function test_nobody_is_reminded_when_reminders_are_turned_off()
    {
        Notification::fake();
        app(UpdateSettings::class)->handle(User::factory()->admin()->create(), ['drop_off_reminder_days_before' => 0]);
        $order = Order::factory()->create(['created_at' => now()->subDays(6)->subHours(23)]);

        $this->assertSame(0, app(SendDropOffReminders::class)->handle());

        Notification::assertNothingSent();
        $this->assertNull($order->fresh()?->drop_off_reminded_at);
    }

    public function test_the_window_follows_the_saved_settings()
    {
        Notification::fake();
        app(UpdateSettings::class)->handle(User::factory()->admin()->create(), [
            'unclaimed_order_days' => 14,
            'drop_off_reminder_days_before' => 4,
        ]);

        $notYet = Order::factory()->create(['created_at' => now()->subDays(9)]);
        $due = Order::factory()->create(['created_at' => now()->subDays(10)]);

        $this->assertSame(1, app(SendDropOffReminders::class)->handle());

        Notification::assertSentTo($due->customer, DropOffReminder::class);
        Notification::assertNotSentTo($notYet->customer, DropOffReminder::class);
    }

    public function test_the_reminder_is_queued()
    {
        Queue::fake();
        Order::factory()->create(['created_at' => now()->subDays(6)]);

        app(SendDropOffReminders::class)->handle();

        Queue::assertPushed(
            SendQueuedNotifications::class,
            fn (SendQueuedNotifications $job) => $job->notification instanceof DropOffReminder && $job->tries === 3,
        );
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
