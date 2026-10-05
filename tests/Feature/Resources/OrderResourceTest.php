<?php

namespace Tests\Feature\Resources;

use App\Enums\OrderStatus;
use App\Http\Resources\BranchResource;
use App\Http\Resources\DriverJobResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\OrderSummaryResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\TrackingResource;
use App\Http\Resources\UserResource;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_full_order_includes_loaded_relations_only()
    {
        $order = Order::factory()->paid()->create(['tracking_number' => 'KT7Q4M92XD']);

        $bare = (new OrderResource($order->fresh()))->response()->getData(true);
        $this->assertSame('KT-7Q4M92XD', $bare['tracking_number']);
        $this->assertSame(['value' => 'paid', 'label' => 'Paid'], $bare['status']);
        $this->assertFalse($bare['is_final']);
        $this->assertIsInt($bare['final_price_sen']);
        $this->assertArrayNotHasKey('payment', $bare);
        $this->assertArrayNotHasKey('status_events', $bare);
        $this->assertArrayNotHasKey('customer', $bare);
        // Customers are not told which rate card priced their order.
        $this->assertArrayNotHasKey('estimated_rate_card', $bare);
        $this->assertArrayNotHasKey('final_rate_card', $bare);

        $loaded = (new OrderResource(
            $order->fresh(['branch', 'payment.receivedBy', 'statusEvents.branch', 'latestAttempt'])->loadCount('failedAttempts'),
        ))->response()->getData(true);

        $this->assertSame($order->payment?->receipt_number, $loaded['payment']['receipt_number']);
        $this->assertSame(['value' => 'cash', 'label' => 'Cash'], $loaded['payment']['method']);
        $this->assertCount(3, $loaded['status_events']);
        $this->assertSame(['value' => 'created', 'label' => 'Created'], $loaded['status_events'][0]['status']);
        $this->assertNull($loaded['latest_attempt']);
        $this->assertSame(0, $loaded['failed_attempts']);
        $this->assertIsFloat($loaded['branch']['latitude']);
    }

    public function test_the_receivers_email_is_only_in_the_full_order()
    {
        $order = Order::factory()->assigned()->create(['receiver_email' => 'daniel@example.com'])->fresh();
        $this->assertNotNull($order);

        // For the customer's own order page and the staff and admin views.
        $this->assertSame('daniel@example.com', (new OrderResource($order))->resolve()['receiver_email']);

        // Not in lists, nor for drivers or public tracking.
        $this->assertArrayNotHasKey('receiver_email', (new OrderSummaryResource($order))->resolve());
        $this->assertArrayNotHasKey('receiver_email', (new DriverJobResource($order))->resolve());
        $this->assertStringNotContainsString('daniel@example.com', (string) json_encode((new TrackingResource($order))->resolve()));
    }

    public function test_dates_are_iso_8601_utc_and_scheduled_dates_are_plain_dates()
    {
        $this->travelTo('2026-09-29 02:15:00');
        $order = Order::factory()->assigned()->create();

        $data = (new OrderSummaryResource($order->fresh()))->response()->getData(true);

        $this->assertSame('2026-09-29T02:15:00Z', $data['created_at']);
        $this->assertSame('2026-09-29', $data['scheduled_for']);
        $this->assertSame('2026-09-29', $data['expected_delivery']);

        // The next day the delivery is still on the van: expected today, not yesterday.
        $this->travelTo('2026-09-30 02:15:00');
        $order->forceFill(['status' => OrderStatus::PickedUp])->save();
        $data = (new OrderSummaryResource($order->fresh()))->response()->getData(true);

        $this->assertSame('2026-09-29', $data['scheduled_for']);
        $this->assertSame('2026-09-30', $data['expected_delivery']);
    }

    public function test_the_payment_receipt_carries_its_order()
    {
        $order = Order::factory()->paid()->create(['receiver_email' => 'daniel@example.com']);

        $data = (new PaymentResource($order->payment()->with(['order', 'branch', 'receivedBy'])->firstOrFail()))->response()->getData(true);

        $this->assertSame($order->formatted_tracking_number, $data['order']['tracking_number']);
        $this->assertSame($order->receiver_name, $data['order']['receiver_name']);
        $this->assertArrayHasKey('name', $data['received_by']);

        // Only what the receipt prints: no one's phone number, email or street address.
        $this->assertSame([
            'id', 'tracking_number', 'item_name', 'receiver_name', 'city', 'postcode',
            'measured_weight_g', 'length_cm', 'width_cm', 'height_cm', 'chargeable_weight_g',
        ], array_keys($data['order']));
    }

    public function test_user_accounts_never_expose_secrets()
    {
        $user = User::factory()->driver()->withTwoFactor()->create();

        $data = (new UserResource(User::withJobsCountOn(today())->findOrFail($user->id)))->response()->getData(true);

        $this->assertSame(['value' => 'driver', 'label' => 'Driver'], $data['role']);
        $this->assertSame(0, $data['jobs_count']);
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('two_factor_secret', $data);
        $this->assertArrayNotHasKey('remember_token', $data);
    }

    public function test_branch_coordinates_are_numbers()
    {
        $branch = Branch::factory()->create(['latitude' => 3.1185, 'longitude' => 101.6225]);

        $data = (new BranchResource($branch->fresh()))->response()->getData(true);

        $this->assertSame(3.1185, $data['latitude']);
        $this->assertSame(101.6225, $data['longitude']);
    }
}
