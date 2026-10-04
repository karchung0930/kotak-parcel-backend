<?php

namespace Tests\Feature\Console;

use App\Models\Order;
use App\Models\User;
use App\Notifications\DriverRunSheet;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendRunSheetsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_command_queues_the_run_sheets_once_a_day()
    {
        Notification::fake();
        $driver = User::factory()->driver()->create();
        Order::factory()->assigned($driver)->create();

        $this->artisan('drivers:send-run-sheets')
            ->expectsOutput('Queued 1 run sheet(s).')
            ->assertSuccessful();
        $this->artisan('drivers:send-run-sheets')
            ->expectsOutput('Queued 0 run sheet(s).')
            ->assertSuccessful();

        Notification::assertSentToTimes($driver, DriverRunSheet::class, 1);
    }

    public function test_it_runs_every_morning_at_seven_malaysia_time()
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'drivers:send-run-sheets'));

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame('0 7 * * *', $event->expression);
        $this->assertSame('Asia/Kuala_Lumpur', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_the_schedule_lists_the_run_sheets()
    {
        Artisan::call('schedule:list');

        $this->assertStringContainsString('drivers:send-run-sheets', Artisan::output());
    }
}
