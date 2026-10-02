<?php

namespace Tests\Feature\Delivery;

use App\Actions\Delivery\AssignDriver;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\DeliveryAttempt;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AssignDriverTest extends TestCase
{
    use RefreshDatabase;

    private AssignDriver $assignDriver;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assignDriver = app(AssignDriver::class);
        $this->admin = User::factory()->admin()->create();
    }

    public function test_admins_schedule_a_paid_parcel_with_a_driver()
    {
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->paid()->create();
        $date = today(config('kotak.timezone'))->addDay();

        $order = $this->assignDriver->handle($order, $this->admin, $driver, $date);

        $this->assertSame(OrderStatus::Assigned, $order->status);
        $this->assertSame($driver->id, $order->driver_id);
        $this->assertSame($date->toDateString(), $order->scheduled_for?->toDateString());
        $this->assertSame(
            'Scheduled for delivery on '.$date->format('j M Y').'.',
            $order->latestStatusEvent()->firstOrFail()->note,
        );
    }

    public function test_today_in_malaysia_is_a_valid_delivery_date()
    {
        // 23:30 UTC is already the next day in Kuala Lumpur.
        $this->travelTo(now()->setTimezone('UTC')->setTime(23, 30));
        $order = Order::factory()->paid()->create();

        $order = $this->assignDriver->handle(
            $order, $this->admin, User::factory()->driver()->create(), today('Asia/Kuala_Lumpur'),
        );

        $this->assertSame(OrderStatus::Assigned, $order->status);
    }

    public function test_the_delivery_date_cannot_be_in_the_past()
    {
        $order = Order::factory()->paid()->create();

        $this->expectException(ValidationException::class);

        $this->assignDriver->handle(
            $order, $this->admin, User::factory()->driver()->create(), today(config('kotak.timezone'))->subDay(),
        );
    }

    public function test_only_active_drivers_can_be_assigned()
    {
        $order = Order::factory()->paid()->create();
        $date = today(config('kotak.timezone'));

        foreach ([User::factory()->create(), User::factory()->staff()->create(), User::factory()->driver()->inactive()->create()] as $user) {
            try {
                $this->assignDriver->handle($order, $this->admin, $user, $date);
                $this->fail("{$user->role->value} must not be assigned.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('driver_id', $e->errors());
            }
        }

        $this->assertSame(OrderStatus::Paid, $order->fresh()?->status);
    }

    public function test_unpaid_orders_cannot_be_assigned()
    {
        $order = Order::factory()->droppedOff()->create();

        $this->expectException(InvalidStatusTransition::class);

        $this->assignDriver->handle($order, $this->admin, User::factory()->driver()->create(), today(config('kotak.timezone')));
    }

    public function test_failed_deliveries_can_be_rescheduled_while_attempts_remain()
    {
        $order = Order::factory()->deliveryFailed()->create();
        DeliveryAttempt::factory()->failed()->for($order)->create(['driver_id' => $order->driver_id]);
        $driver = User::factory()->driver()->create();

        $order = $this->assignDriver->handle($order, $this->admin, $driver, today(config('kotak.timezone')));

        $this->assertSame(OrderStatus::Assigned, $order->status);
        $this->assertSame($driver->id, $order->driver_id);
    }

    public function test_an_assigned_delivery_is_reassigned_to_another_driver_on_the_same_day()
    {
        $order = Order::factory()->assigned()->create();
        $driver = User::factory()->driver()->create();
        $date = today(config('kotak.timezone'));

        $order = $this->assignDriver->handle($order, $this->admin, $driver, $date);

        $this->assertSame(OrderStatus::Assigned, $order->status);
        $this->assertSame($driver->id, $order->driver_id);
        $this->assertSame(
            'Reassigned to another driver for delivery on '.$date->format('j M Y').'.',
            $order->latestStatusEvent()->firstOrFail()->note,
        );
    }

    public function test_reassigning_keeps_the_failed_attempts_so_the_limit_still_applies()
    {
        // Failed twice, then rescheduled: the third attempt is the last one.
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->assigned($driver)->create();
        DeliveryAttempt::factory()->failed()->for($order)->count(2)->create(['driver_id' => $driver->id]);

        $order = $this->assignDriver->handle(
            $order, $this->admin, User::factory()->driver()->create(), today(config('kotak.timezone'))->addDay(),
        );

        $this->assertSame(2, $order->failedAttemptsCount());
        $this->assertTrue($order->canBeAssigned());
    }

    public function test_deliveries_out_for_delivery_cannot_be_reassigned()
    {
        $order = Order::factory()->pickedUp()->create();

        $this->expectException(InvalidStatusTransition::class);

        $this->assignDriver->handle($order, $this->admin, User::factory()->driver()->create(), today(config('kotak.timezone')));
    }

    public function test_failed_deliveries_cannot_be_rescheduled_after_the_maximum_attempts()
    {
        $order = Order::factory()->deliveryFailed()->create();
        DeliveryAttempt::factory()->failed()->for($order)->count(2)->create(['driver_id' => $order->driver_id]);

        $this->assertSame(3, $order->failedAttemptsCount());
        $this->assertFalse($order->canBeAssigned());

        $this->expectException(InvalidStatusTransition::class);
        $this->expectExceptionMessage('maximum of 3 delivery attempts');

        $this->assignDriver->handle($order, $this->admin, User::factory()->driver()->create(), today(config('kotak.timezone')));
    }
}
