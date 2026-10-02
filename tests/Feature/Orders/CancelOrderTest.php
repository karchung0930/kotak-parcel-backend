<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\CancelOrder;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_customers_cancel_their_order_before_drop_off_with_a_reason()
    {
        $order = Order::factory()->create();

        $order = app(CancelOrder::class)->handle($order, $order->customer, 'Changed my mind.');

        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame('Changed my mind.', $order->latestStatusEvent()->firstOrFail()->note);
    }

    public function test_customers_cannot_cancel_after_drop_off()
    {
        $order = Order::factory()->droppedOff()->create();

        $this->expectException(InvalidStatusTransition::class);

        app(CancelOrder::class)->handle($order, $order->customer);
    }

    public function test_customers_cannot_cancel_someone_elses_order()
    {
        $order = Order::factory()->create();

        try {
            app(CancelOrder::class)->handle($order, User::factory()->create());
            $this->fail('Another customer must not cancel the order.');
        } catch (AuthorizationException) {
            $this->assertSame(OrderStatus::Created, $order->fresh()?->status);
        }
    }

    public function test_drivers_cannot_cancel_orders()
    {
        $order = Order::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(CancelOrder::class)->handle($order, User::factory()->driver()->create());
    }

    public function test_staff_cancel_when_the_customer_refuses_the_final_price()
    {
        $staff = User::factory()->staff()->create();
        $order = Order::factory()->droppedOff()->create();

        $order = app(CancelOrder::class)->handle($order, $staff);

        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('Customer declined the final price.', $order->latestStatusEvent()->firstOrFail()->note);
    }

    public function test_staff_cannot_cancel_before_the_parcel_is_weighed()
    {
        $order = Order::factory()->create();

        $this->expectException(InvalidStatusTransition::class);

        app(CancelOrder::class)->handle($order, User::factory()->staff()->create());
    }

    public function test_paid_orders_cannot_be_cancelled()
    {
        $order = Order::factory()->paid()->create();

        $this->expectException(InvalidStatusTransition::class);

        app(CancelOrder::class)->handle($order, User::factory()->admin()->create());
    }
}
