<?php

namespace Tests\Feature\Driver;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PickUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_the_assigned_driver_picks_up_the_parcel()
    {
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->assigned($driver)->create();

        $this->actingAs($driver)
            ->from(route('driver.jobs'))
            ->post(route('driver.jobs.pickup', $order))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('driver.jobs'))
            ->assertInertiaFlash('toast', [
                'type' => 'success',
                'message' => "Picked up {$order->formatted_tracking_number}.",
            ]);

        $order->refresh();
        $this->assertSame(OrderStatus::PickedUp, $order->status);
        $this->assertSame($driver->id, $order->latestStatusEvent()->firstOrFail()->actor_id);
    }

    public function test_drivers_cannot_pick_up_another_drivers_job()
    {
        $order = Order::factory()->assigned()->create();

        $this->actingAs(User::factory()->driver()->create())
            ->post(route('driver.jobs.pickup', $order))
            ->assertForbidden();

        $this->assertSame(OrderStatus::Assigned, $order->fresh()?->status);
    }

    public function test_a_parcel_cannot_be_picked_up_twice()
    {
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->pickedUp($driver)->create();
        $events = $order->statusEvents()->count();

        $this->actingAs($driver)
            ->post(route('driver.jobs.pickup', $order))
            ->assertSessionHasErrors('status');

        $this->assertSame($events, $order->statusEvents()->count());
    }

    public function test_other_roles_cannot_pick_up_parcels()
    {
        $order = Order::factory()->assigned()->create();

        foreach ([$order->customer, User::factory()->staff()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->post(route('driver.jobs.pickup', $order))->assertForbidden();
        }

        $this->assertSame(OrderStatus::Assigned, $order->fresh()?->status);
    }
}
