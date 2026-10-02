<?php

namespace Tests\Feature\Policies;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_are_visible_to_their_customer_staff_admins_and_assigned_driver_only()
    {
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->assigned($driver)->create();

        $this->assertTrue($order->customer->can('view', $order));
        $this->assertTrue(User::factory()->staff()->create()->can('view', $order));
        $this->assertTrue(User::factory()->admin()->create()->can('view', $order));
        $this->assertTrue($driver->can('view', $order));

        $this->assertFalse(User::factory()->create()->can('view', $order));
        $this->assertFalse(User::factory()->driver()->create()->can('view', $order));
    }

    public function test_drivers_see_their_deliveries_only_while_they_are_open()
    {
        $driver = User::factory()->driver()->create();

        $this->assertTrue($driver->can('view', Order::factory()->assigned($driver)->create()));
        $this->assertTrue($driver->can('view', Order::factory()->pickedUp($driver)->create()));
        $this->assertFalse($driver->can('view', Order::factory()->delivered($driver)->create()));
        $this->assertFalse($driver->can('view', Order::factory()->deliveryFailed($driver)->create()));

        // Customers, staff and admins keep seeing finished orders.
        $delivered = Order::factory()->delivered($driver)->create();
        $this->assertTrue($delivered->customer->can('view', $delivered));
        $this->assertTrue(User::factory()->staff()->create()->can('view', $delivered));
    }

    public function test_only_customers_create_orders()
    {
        $this->assertTrue(User::factory()->create()->can('create', Order::class));
        $this->assertFalse(User::factory()->staff()->create()->can('create', Order::class));
        $this->assertFalse(User::factory()->driver()->create()->can('create', Order::class));
        $this->assertFalse(User::factory()->admin()->create()->can('create', Order::class));
    }

    public function test_customers_cancel_their_own_orders_only_before_drop_off()
    {
        $created = Order::factory()->create();
        $droppedOff = Order::factory()->droppedOff()->create();

        $this->assertTrue($created->customer->can('cancel', $created));
        $this->assertFalse($droppedOff->customer->can('cancel', $droppedOff));
        $this->assertFalse(User::factory()->create()->can('cancel', $created));
    }

    public function test_staff_cancel_only_weighed_parcels()
    {
        $staff = User::factory()->staff()->create();

        $this->assertTrue($staff->can('cancel', Order::factory()->droppedOff()->create()));
        $this->assertFalse($staff->can('cancel', Order::factory()->create()));
        $this->assertFalse($staff->can('cancel', Order::factory()->paid()->create()));
        $this->assertFalse(User::factory()->driver()->create()->can('cancel', Order::factory()->create()));
    }

    public function test_counter_processing_is_for_staff_and_admins()
    {
        $order = Order::factory()->create();

        $this->assertTrue(User::factory()->staff()->create()->can('process', $order));
        $this->assertTrue(User::factory()->admin()->create()->can('process', $order));
        $this->assertFalse($order->customer->can('process', $order));
        $this->assertFalse(User::factory()->driver()->create()->can('process', $order));
    }

    public function test_dispatch_decisions_are_for_admins()
    {
        $order = Order::factory()->paid()->create();
        $staff = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();

        $this->assertTrue($admin->can('assign', $order));
        $this->assertTrue($admin->can('returnToSender', $order));
        $this->assertFalse($staff->can('assign', $order));
        $this->assertFalse($staff->can('returnToSender', $order));
        $this->assertFalse($order->customer->can('assign', $order));
    }

    public function test_only_the_assigned_driver_delivers()
    {
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->assigned($driver)->create();

        $this->assertTrue($driver->can('deliver', $order));
        $this->assertFalse(User::factory()->driver()->create()->can('deliver', $order));
        $this->assertFalse(User::factory()->admin()->create()->can('deliver', $order));
        $this->assertFalse($order->customer->can('deliver', $order));
    }

    public function test_the_proof_photo_is_visible_to_the_owner_staff_admins_and_assigned_driver()
    {
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->delivered($driver)->create();

        $this->assertTrue($order->customer->can('viewProof', $order));
        $this->assertTrue(User::factory()->staff()->create()->can('viewProof', $order));
        $this->assertTrue(User::factory()->admin()->create()->can('viewProof', $order));
        $this->assertTrue($driver->can('viewProof', $order));
        $this->assertFalse(User::factory()->create()->can('viewProof', $order));
        $this->assertFalse(User::factory()->driver()->create()->can('viewProof', $order));
    }
}
