<?php

namespace Tests\Feature\Http;

use App\Actions\Delivery\RecordDeliverySuccess;
use App\Http\Resources\DeliveryAttemptResource;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProofOfDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->driver = User::factory()->driver()->create();
        $this->order = app(RecordDeliverySuccess::class)->handle(
            Order::factory()->pickedUp($this->driver)->create(),
            $this->driver,
            'Daniel Lim',
            UploadedFile::fake()->image('door.png'),
        );
    }

    public function test_the_customer_staff_admin_and_assigned_driver_can_see_the_photo()
    {
        foreach ([$this->order->customer, User::factory()->staff()->create(), User::factory()->admin()->create(), $this->driver] as $user) {
            $this->actingAs($user)
                ->get(route('orders.proof', $this->order))
                ->assertOk()
                ->assertHeader('Content-Type', 'image/png');
        }
    }

    public function test_other_customers_and_drivers_are_forbidden()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('orders.proof', $this->order))
            ->assertForbidden();

        $this->actingAs(User::factory()->driver()->create())
            ->get(route('orders.proof', $this->order))
            ->assertForbidden();
    }

    public function test_guests_must_log_in()
    {
        $this->get(route('orders.proof', $this->order))->assertRedirect(route('login'));
    }

    public function test_orders_without_a_photo_return_not_found()
    {
        $order = Order::factory()->pickedUp()->create();

        $this->actingAs($order->customer)
            ->get(route('orders.proof', $order))
            ->assertNotFound();
    }

    public function test_the_delivery_attempt_resource_links_to_the_authorised_route()
    {
        $attempt = $this->order->latestAttempt()->firstOrFail();

        $this->assertSame(
            route('orders.proof', $this->order),
            (new DeliveryAttemptResource($attempt))->resolve()['photo_url'],
        );
        $this->assertStringNotContainsString('pod/', route('orders.proof', $this->order));
    }
}
