<?php

namespace Tests\Feature\Driver;

use App\Actions\Delivery\AssignDriver;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JobShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
    }

    public function test_the_assigned_driver_sees_the_receiver_address_and_pick_up_branch()
    {
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->assigned($driver)->create([
            'receiver_name' => 'Daniel Lim',
            'receiver_phone' => '+60137654321',
            'address_line1' => '12 Jalan SS2/24',
            'address_line2' => 'Unit 3-1',
        ]);

        $this->actingAs($driver)
            ->get(route('driver.jobs.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('driver/JobShow')
                ->where('order.id', $order->id)
                ->where('order.receiver_name', 'Daniel Lim')
                ->where('order.receiver_phone', '+60137654321')
                ->where('order.address_line1', '12 Jalan SS2/24')
                ->where('order.address_line2', 'Unit 3-1')
                ->where('order.branch.id', $order->branch_id)
                ->has('order.branch.address')
                ->where('order.failed_attempts', 0)
                ->has('order.delivery_attempts', 0)
                ->has('failureReasons', 5)
                ->where('failureReasons.0', ['value' => 'recipient_unavailable', 'label' => 'Recipient not available']),
            );
    }

    public function test_the_driver_never_receives_the_senders_details_or_prices()
    {
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->assigned($driver)->create();

        $this->actingAs($driver)
            ->get(route('driver.jobs.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('order.customer')
                ->missing('order.sender_name')
                ->missing('order.sender_phone')
                ->missing('order.estimated_price_sen')
                ->missing('order.final_price_sen')
                ->missing('order.payment'),
            );
    }

    public function test_earlier_failed_attempts_are_shown_when_the_parcel_is_rescheduled()
    {
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->deliveryFailed($driver)->create();
        $order->latestAttempt()->firstOrFail()->forceFill(['note' => 'Gate code 4455.'])->save();

        app(AssignDriver::class)->handle(
            $order, User::factory()->admin()->create(), $driver, today(config('kotak.timezone')),
        );

        $this->actingAs($driver)
            ->get(route('driver.jobs.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.status.value', 'assigned')
                ->where('order.failed_attempts', 1)
                ->has('order.delivery_attempts', 1)
                ->where('order.delivery_attempts.0.outcome.value', 'failed')
                // Drivers see each other's notes about the address.
                ->where('order.delivery_attempts.0.note', 'Gate code 4455.'),
            );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function closedJobs(): array
    {
        return ['delivered' => ['delivered'], 'failed' => ['deliveryFailed'], 'returned' => ['returnedToSender']];
    }

    #[DataProvider('closedJobs')]
    public function test_drivers_cannot_reopen_a_job_once_it_is_closed(string $state)
    {
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->{$state}()->create(['driver_id' => $driver->id]);

        $this->actingAs($driver)
            ->get(route('driver.jobs.show', $order))
            ->assertForbidden();
    }

    public function test_drivers_cannot_open_another_drivers_job()
    {
        $order = Order::factory()->assigned()->create();

        $this->actingAs(User::factory()->driver()->create())
            ->get(route('driver.jobs.show', $order))
            ->assertForbidden();
    }

    public function test_drivers_cannot_open_jobs_that_are_not_assigned_to_anyone()
    {
        $order = Order::factory()->paid()->create();

        $this->actingAs(User::factory()->driver()->create())
            ->get(route('driver.jobs.show', $order))
            ->assertForbidden();
    }

    public function test_other_roles_cannot_use_the_driver_pages()
    {
        $order = Order::factory()->assigned()->create();

        $this->actingAs($order->customer)
            ->get(route('driver.jobs.show', $order))
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('driver.jobs.show', $order))
            ->assertForbidden();
    }

    public function test_unknown_jobs_are_not_found()
    {
        $this->actingAs(User::factory()->driver()->create())
            ->get(route('driver.jobs.show', 999))
            ->assertNotFound();
    }
}
