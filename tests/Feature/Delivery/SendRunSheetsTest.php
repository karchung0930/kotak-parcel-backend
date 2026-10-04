<?php

namespace Tests\Feature\Delivery;

use App\Actions\Delivery\SendRunSheets;
use App\Models\Order;
use App\Models\User;
use App\Notifications\DriverRunSheet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class SendRunSheetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 7am on Monday 5 October in Kuala Lumpur, when the schedule runs.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 07:00', 'Asia/Kuala_Lumpur'));
    }

    public function test_each_driver_with_jobs_today_gets_one_run_sheet_of_today_and_overdue_jobs()
    {
        Notification::fake();
        $ravi = User::factory()->driver()->create();
        $siti = User::factory()->driver()->create();
        $wong = User::factory()->driver()->create();
        $faizal = User::factory()->driver()->create();

        $today = Order::factory()->assigned($ravi)->create(['postcode' => '50450']);
        $overdue = Order::factory()->pickedUp($ravi)->create(['scheduled_for' => '2026-10-02']);
        Order::factory()->assigned($ravi)->create(['scheduled_for' => '2026-10-06']);
        Order::factory()->delivered($ravi)->create();
        Order::factory()->deliveryFailed($ravi)->create();
        $sitis = Order::factory()->assigned($siti)->create();
        // Wong only has work tomorrow; Faizal has none.
        Order::factory()->assigned($wong)->create(['scheduled_for' => '2026-10-06']);

        $this->assertSame(2, app(SendRunSheets::class)->handle());

        Notification::assertSentToTimes($ravi, DriverRunSheet::class, 1);
        Notification::assertSentTo($ravi, DriverRunSheet::class, fn (DriverRunSheet $sheet) => $sheet->day->toDateString() === '2026-10-05'
            && $sheet->jobsFor($ravi)->modelKeys() === [$overdue->id, $today->id]);
        Notification::assertSentTo($siti, DriverRunSheet::class, fn (DriverRunSheet $sheet) => $sheet->jobsFor($siti)->modelKeys() === [$sitis->id]);
        Notification::assertNothingSentTo([$wong, $faizal]);
    }

    public function test_the_run_sheet_lists_the_same_jobs_as_my_jobs()
    {
        Notification::fake();
        $driver = User::factory()->driver()->create();
        Order::factory()->assigned($driver)->create(['postcode' => '50450']);
        Order::factory()->assigned($driver)->create(['postcode' => '47300']);
        Order::factory()->pickedUp($driver)->create(['scheduled_for' => '2026-10-03']);
        Order::factory()->assigned($driver)->create(['scheduled_for' => '2026-10-01', 'postcode' => '43000']);
        Order::factory()->assigned($driver)->create(['scheduled_for' => '2026-10-07']);

        app(SendRunSheets::class)->handle();

        $sheet = Notification::sent($driver, DriverRunSheet::class)->first();
        $this->assertInstanceOf(DriverRunSheet::class, $sheet);

        $this->actingAs($driver)
            ->get(route('driver.jobs'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('driver/Jobs')
                ->where('jobs', fn ($jobs) => collect($jobs)->pluck('id')->all() === $sheet->jobsFor($driver)->modelKeys()),
            );
        $this->assertCount(4, $sheet->jobsFor($driver));
    }

    public function test_today_means_today_in_malaysia()
    {
        Notification::fake();
        // 23:30 UTC on 4 October is already 07:30 on 5 October in Kuala Lumpur.
        $this->travelTo(CarbonImmutable::parse('2026-10-04 23:30', 'UTC'));
        $driver = User::factory()->driver()->create();
        $job = Order::factory()->assigned($driver)->create(['scheduled_for' => '2026-10-05']);

        $this->assertSame(1, app(SendRunSheets::class)->handle());

        Notification::assertSentTo($driver, DriverRunSheet::class, fn (DriverRunSheet $sheet) => $sheet->day->toDateString() === '2026-10-05'
            && $sheet->jobsFor($driver)->modelKeys() === [$job->id]);
    }

    public function test_deactivated_drivers_and_unverified_addresses_are_skipped()
    {
        Notification::fake();
        $leaver = User::factory()->driver()->create();
        Order::factory()->assigned($leaver)->create();
        $leaver->forceFill(['is_active' => false])->save();
        $unverified = User::factory()->driver()->unverified()->create();
        Order::factory()->assigned($unverified)->create();

        $this->assertSame(0, app(SendRunSheets::class)->handle());

        Notification::assertNothingSent();
    }

    public function test_a_driver_gets_one_run_sheet_a_day_however_often_it_runs()
    {
        Notification::fake();
        $driver = User::factory()->driver()->create();
        Order::factory()->assigned($driver)->create();

        $this->assertSame(1, app(SendRunSheets::class)->handle());
        $this->assertSame(0, app(SendRunSheets::class)->handle());

        // Later the same day, with a new job, still not twice.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 18:00', 'Asia/Kuala_Lumpur'));
        Order::factory()->assigned($driver)->create();
        $this->assertSame(0, app(SendRunSheets::class)->handle());
        Notification::assertSentToTimes($driver, DriverRunSheet::class, 1);

        // The next morning the jobs still open are overdue, and a new sheet goes out.
        $this->travelTo(CarbonImmutable::parse('2026-10-06 07:00', 'Asia/Kuala_Lumpur'));
        $this->assertSame(1, app(SendRunSheets::class)->handle());
        Notification::assertSentToTimes($driver, DriverRunSheet::class, 2);
    }

    public function test_a_driver_whose_run_sheet_cannot_be_queued_does_not_hold_up_the_others()
    {
        Exceptions::fake();
        $ravi = User::factory()->driver()->create();
        $siti = User::factory()->driver()->create();
        Order::factory()->assigned($ravi)->create();
        Order::factory()->assigned($siti)->create();

        // Queueing Ravi's sheet fails, as when the queue's database is briefly unavailable.
        $notifications = new class extends NotificationFake
        {
            public ?int $failFor = null;

            public function send($notifiables, $notification)
            {
                if ($notifiables instanceof User && $notifiables->id === $this->failFor) {
                    throw new RuntimeException('The queue is unavailable.');
                }

                parent::send($notifiables, $notification);
            }
        };
        $notifications->failFor = $ravi->id;
        Notification::swap($notifications);

        $this->assertSame(1, app(SendRunSheets::class)->handle());

        Notification::assertNothingSentTo($ravi);
        Notification::assertSentToTimes($siti, DriverRunSheet::class, 1);
        Exceptions::assertReported(RuntimeException::class);

        // Ravi's day is not used up: running it again sends his, and not Siti's twice.
        $notifications->failFor = null;
        $this->assertSame(1, app(SendRunSheets::class)->handle());

        Notification::assertSentToTimes($ravi, DriverRunSheet::class, 1);
        Notification::assertSentToTimes($siti, DriverRunSheet::class, 1);
    }

    public function test_the_run_sheet_is_queued()
    {
        Queue::fake();
        Order::factory()->assigned()->create();

        app(SendRunSheets::class)->handle();

        Queue::assertPushed(
            SendQueuedNotifications::class,
            fn (SendQueuedNotifications $job) => $job->notification instanceof DriverRunSheet && $job->tries === 3,
        );
    }
}
