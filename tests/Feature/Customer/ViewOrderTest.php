<?php

namespace Tests\Feature\Customer;

use App\Actions\Settings\UpdateSettings;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ViewOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pages are checked through their Inertia props, not the built assets.
        $this->withoutVite();
    }

    public function test_customers_see_their_new_order_and_can_cancel_it()
    {
        $order = Order::factory()->create();

        $this->actingAs($order->customer)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('orders/Show')
                ->where('order.id', $order->id)
                ->where('order.tracking_number', $order->formatted_tracking_number)
                ->where('order.status', ['value' => 'created', 'label' => 'Created'])
                ->where('order.status_description', 'Your order is ready to be dropped off at your chosen Kotak branch.')
                ->where('order.receiver_name', $order->receiver_name)
                ->where('order.receiver_phone', $order->receiver_phone)
                ->where('order.branch.id', $order->branch_id)
                ->where('order.payment', null)
                ->where('order.latest_attempt', null)
                ->has('order.status_events', 1)
                ->where('canCancel', true));
    }

    public function test_customers_follow_the_delivery_with_payment_attempt_and_history()
    {
        $order = Order::factory()->delivered()->create();
        $order->deliveryAttempts()->update(['photo_path' => "pod/{$order->id}/door.png"]);
        $branch = Branch::factory()->create(['name' => 'Petaling Jaya - SS2', 'city' => 'Petaling Jaya']);
        $order->statusEvents()->where('to_status', 'dropped_off')->update(['branch_id' => $branch->id]);

        $this->actingAs($order->customer)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.status.value', 'delivered')
                ->where('order.payment.amount_sen', $order->final_price_sen)
                ->where('order.payment.receipt_number', $order->payment?->receipt_number)
                ->where('order.latest_attempt.outcome.value', 'delivered')
                ->where('order.latest_attempt.photo_url', route('orders.proof', $order))
                ->has('order.status_events', 6)
                ->where('order.status_events.1.status.value', 'dropped_off')
                ->where('order.status_events.1.branch', ['id' => $branch->id, 'name' => 'Petaling Jaya - SS2', 'city' => 'Petaling Jaya'])
                // Staff and driver details stay internal.
                ->missing('order.customer')
                ->missing('order.driver')
                ->missing('order.status_events.1.actor')
                ->missing('order.payment.received_by')
                ->missing('order.latest_attempt.driver')
                ->where('canCancel', false));
    }

    public function test_customers_see_why_a_delivery_failed_but_not_the_drivers_note()
    {
        $order = Order::factory()->deliveryFailed()->create();
        $order->latestAttempt()->firstOrFail()->forceFill(['note' => 'Internal: guard says the tenant owes rent.'])->save();

        $this->actingAs($order->customer)
            ->get(route('orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.latest_attempt.failure_reason.value', 'recipient_unavailable')
                ->missing('order.latest_attempt.note'));

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.delivery_attempts.0.note', 'Internal: guard says the tenant owes rent.'));
    }

    public function test_orders_waiting_for_drop_off_show_the_deadline_worked_out_by_the_server()
    {
        // Ordered at 23:30 on 3 October in Kuala Lumpur (15:30 UTC): 7 days later is 10 October there.
        $order = Order::factory()->create(['created_at' => CarbonImmutable::parse('2026-10-03 15:30:00', 'UTC')]);

        $this->actingAs($order->customer)
            ->get(route('orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page->where('order.drop_off_deadline', '2026-10-10'));

        $this->actingAs($order->customer)
            ->get(route('orders.index'))
            ->assertInertia(fn (Assert $page) => $page->where('orders.data.0.drop_off_deadline', '2026-10-10'));
    }

    public function test_the_deadline_a_customer_was_given_stays_when_the_limit_changes()
    {
        $order = Order::factory()->create(['created_at' => CarbonImmutable::parse('2026-10-03 15:30:00', 'UTC')]);

        app(UpdateSettings::class)->handle(User::factory()->admin()->create(), ['unclaimed_order_days' => 3, 'drop_off_reminder_days_before' => 1]);

        $this->actingAs($order->customer)
            ->get(route('orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page->where('order.drop_off_deadline', '2026-10-10'));
    }

    public function test_there_is_no_deadline_once_the_parcel_is_dropped_off()
    {
        $order = Order::factory()->droppedOff()->create();

        $this->actingAs($order->customer)
            ->get(route('orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page->where('order.drop_off_deadline', null));
    }

    public function test_orders_cannot_be_cancelled_once_dropped_off()
    {
        $order = Order::factory()->droppedOff()->create();

        $this->actingAs($order->customer)
            ->get(route('orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page->where('canCancel', false));
    }

    public function test_customers_cannot_see_someone_elses_order()
    {
        $order = Order::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('orders.show', $order))
            ->assertForbidden();
    }

    public function test_unknown_orders_are_not_found()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('orders.show', 999999))
            ->assertNotFound();
    }

    public function test_guests_are_sent_to_the_login_page()
    {
        $this->get(route('orders.show', Order::factory()->create()))->assertRedirect(route('login'));
    }
}
