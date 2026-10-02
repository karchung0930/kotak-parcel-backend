<?php

namespace Tests\Feature\Driver;

use App\Models\DeliveryAttempt;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class JobListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
    }

    public function test_drivers_see_only_their_own_open_jobs_for_today()
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-29 10:00', 'Asia/Kuala_Lumpur'));
        $driver = User::factory()->driver()->create();
        $assigned = Order::factory()->assigned($driver)->create(['postcode' => '50450']);
        $pickedUp = Order::factory()->pickedUp($driver)->create(['postcode' => '47300']);
        Order::factory()->assigned()->create();
        Order::factory()->delivered($driver)->create();
        Order::factory()->assigned($driver)->create(['scheduled_for' => '2026-09-30']);

        $this->actingAs($driver)
            ->get(route('driver.jobs'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('driver/Jobs')
                ->where('date', '2026-09-29')
                ->has('jobs', 2)
                // Sorted by postcode so nearby stops are listed together.
                ->where('jobs.0.id', $pickedUp->id)
                ->where('jobs.0.status.value', 'picked_up')
                ->where('jobs.0.failed_attempts', 0)
                ->where('jobs.0.branch.id', $pickedUp->branch_id)
                ->where('jobs.1.id', $assigned->id)
                ->missing('jobs.0.customer')
                ->where('counts', ['assigned' => 1, 'picked_up' => 1, 'delivered' => 1, 'failed' => 0]),
            );
    }

    public function test_today_means_today_in_malaysia()
    {
        // 17:30 UTC on 29 September is already 01:30 on 30 September in Kuala Lumpur.
        $this->travelTo(CarbonImmutable::parse('2026-09-29 17:30', 'UTC'));
        $driver = User::factory()->driver()->create();
        $job = Order::factory()->assigned($driver)->create(['scheduled_for' => '2026-09-30']);
        Order::factory()->assigned($driver)->create(['scheduled_for' => '2026-10-01']);

        $this->actingAs($driver)
            ->get(route('driver.jobs'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('date', '2026-09-30')
                ->has('jobs', 1)
                ->where('jobs.0.id', $job->id)
                ->where('jobs.0.is_overdue', false),
            );
    }

    public function test_jobs_left_open_on_earlier_days_are_carried_over_to_today()
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-30 10:00', 'Asia/Kuala_Lumpur'));
        $driver = User::factory()->driver()->create();
        $today = Order::factory()->assigned($driver)->create(['scheduled_for' => '2026-09-30']);
        $inTheVan = Order::factory()->pickedUp($driver)->create(['scheduled_for' => '2026-09-29']);
        $notCollected = Order::factory()->assigned($driver)->create(['scheduled_for' => '2026-09-27']);
        Order::factory()->delivered($driver)->create(['scheduled_for' => '2026-09-28']);

        $this->actingAs($driver)
            ->get(route('driver.jobs'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('jobs', 3)
                // Overdue jobs come first, oldest first, and are flagged.
                ->where('jobs.0.id', $notCollected->id)
                ->where('jobs.0.is_overdue', true)
                ->where('jobs.0.scheduled_for', '2026-09-27')
                ->where('jobs.1.id', $inTheVan->id)
                ->where('jobs.1.is_overdue', true)
                ->where('jobs.2.id', $today->id)
                ->where('jobs.2.is_overdue', false)
                ->where('counts.assigned', 2)
                ->where('counts.picked_up', 1),
            );
    }

    public function test_drivers_can_look_at_another_day()
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-29 10:00', 'Asia/Kuala_Lumpur'));
        $driver = User::factory()->driver()->create();
        $job = Order::factory()->assigned($driver)->create(['scheduled_for' => '2026-10-02']);
        // Carried over to today's list only, not to other days.
        Order::factory()->assigned($driver)->create(['scheduled_for' => '2026-10-01']);

        $this->actingAs($driver)
            ->get(route('driver.jobs', ['date' => '2026-10-02']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('date', '2026-10-02')
                ->has('jobs', 1)
                ->where('jobs.0.id', $job->id),
            );
    }

    public function test_the_list_carries_what_the_delivery_needs_and_nothing_about_the_sender()
    {
        $driver = User::factory()->driver()->create();
        $job = Order::factory()->assigned($driver)->create(['receiver_phone' => '+60137654321']);

        $this->actingAs($driver)
            ->get(route('driver.jobs'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.0.id', $job->id)
                ->where('jobs.0.receiver_phone', '+60137654321')
                ->has('jobs.0.branch.address')
                ->missing('jobs.0.sender_name')
                ->missing('jobs.0.sender_phone')
                ->missing('jobs.0.customer')
                ->missing('jobs.0.estimated_price_sen')
                ->missing('jobs.0.final_price_sen'),
            );
    }

    public function test_the_date_must_be_a_real_calendar_day()
    {
        $driver = User::factory()->driver()->create();

        foreach (['2026-02-30', 'tomorrow', '29/09/2026'] as $date) {
            $this->actingAs($driver)
                ->get(route('driver.jobs', ['date' => $date]))
                ->assertSessionHasErrors('date');
        }
    }

    public function test_completed_attempts_are_counted_by_the_malaysian_day()
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-29 12:00', 'Asia/Kuala_Lumpur'));
        $driver = User::factory()->driver()->create();

        $attempt = fn (string $utc, bool $failed = false) => DeliveryAttempt::factory()
            ->when($failed, fn ($factory) => $factory->failed())
            ->create(['driver_id' => $driver->id, 'attempted_at' => CarbonImmutable::parse($utc, 'UTC')]);

        $attempt('2026-09-28 16:30');           // 00:30 on the 29th in Malaysia
        $attempt('2026-09-29 15:59');           // 23:59 on the 29th in Malaysia
        $attempt('2026-09-29 03:00', failed: true);
        $attempt('2026-09-28 15:30');           // 23:30 on the 28th in Malaysia
        DeliveryAttempt::factory()->create(['attempted_at' => now()]); // another driver

        $this->actingAs($driver)
            ->get(route('driver.jobs'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.delivered', 2)
                ->where('counts.failed', 1),
            );
    }

    public function test_only_drivers_can_open_the_job_list()
    {
        foreach ([User::factory()->create(), User::factory()->staff()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->get(route('driver.jobs'))->assertForbidden();
        }
    }

    public function test_guests_must_log_in()
    {
        $this->get(route('driver.jobs'))->assertRedirect(route('login'));
    }
}
