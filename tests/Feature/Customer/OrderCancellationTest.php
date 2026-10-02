<?php

namespace Tests\Feature\Customer;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customers_cancel_their_order_before_drop_off_with_a_reason()
    {
        $order = Order::factory()->create();

        $this->actingAs($order->customer)
            ->post(route('orders.cancel', $order), ['reason' => 'Sending it by hand instead.'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('orders.show', $order))
            ->assertInertiaFlash('toast.type', 'success');

        $order->refresh();
        $event = $order->latestStatusEvent()->firstOrFail();

        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame(OrderStatus::Cancelled, $event->to_status);
        $this->assertSame('Sending it by hand instead.', $event->note);
        $this->assertSame($order->customer_id, $event->actor_id);
    }

    public function test_the_reason_is_optional()
    {
        $order = Order::factory()->create();

        $this->actingAs($order->customer)
            ->post(route('orders.cancel', $order))
            ->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertNull($order->latestStatusEvent()->firstOrFail()->note);
    }

    public function test_the_reason_must_be_short()
    {
        $order = Order::factory()->create();

        $this->actingAs($order->customer)
            ->post(route('orders.cancel', $order), ['reason' => str_repeat('a', 256)])
            ->assertSessionHasErrors('reason');

        $this->assertSame(OrderStatus::Created, $order->refresh()->status);
    }

    public function test_orders_cannot_be_cancelled_after_drop_off()
    {
        foreach (['droppedOff', 'paid', 'pickedUp', 'delivered', 'cancelled'] as $state) {
            $order = Order::factory()->{$state}()->create();
            $status = $order->status;

            $this->actingAs($order->customer)
                ->post(route('orders.cancel', $order))
                ->assertForbidden();

            $this->assertSame($status, $order->refresh()->status, "A {$state} order was cancelled.");
        }
    }

    public function test_customers_cannot_cancel_someone_elses_order()
    {
        $order = Order::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('orders.cancel', $order))
            ->assertForbidden();

        $this->assertSame(OrderStatus::Created, $order->refresh()->status);
    }

    public function test_guests_cannot_cancel_orders()
    {
        $order = Order::factory()->create();

        $this->post(route('orders.cancel', $order))->assertRedirect(route('login'));

        $this->assertSame(OrderStatus::Created, $order->refresh()->status);
    }
}
