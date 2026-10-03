<?php

namespace Tests\Feature\Console;

use App\Models\Order;
use App\Notifications\DropOffReminder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RemindUnclaimedOrdersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_command_queues_the_reminders_once()
    {
        Notification::fake();
        $order = Order::factory()->create(['created_at' => now()->subDays(6)]);

        $this->artisan('orders:remind-unclaimed')
            ->expectsOutput('Queued 1 drop-off reminder(s).')
            ->assertSuccessful();
        $this->artisan('orders:remind-unclaimed')
            ->expectsOutput('Queued 0 drop-off reminder(s).')
            ->assertSuccessful();

        Notification::assertSentToTimes($order->customer, DropOffReminder::class, 1);
    }

    public function test_it_runs_every_morning_at_nine_malaysia_time()
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'orders:remind-unclaimed'));

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame('0 9 * * *', $event->expression);
        $this->assertSame('Asia/Kuala_Lumpur', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_the_schedule_lists_both_order_jobs()
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertStringContainsString('orders:expire-unclaimed', $output);
        $this->assertStringContainsString('orders:remind-unclaimed', $output);
    }
}
