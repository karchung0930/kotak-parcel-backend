<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\CreateOrder;
use App\Enums\MalaysianState;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreateOrderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function input(Branch $branch): array
    {
        return [
            'branch_id' => $branch->id,
            'receiver_name' => 'Daniel Lim',
            'receiver_phone' => '+60127788990',
            'address_line1' => 'No. 12, Jalan Datuk Sulaiman 1',
            'address_line2' => 'Taman Tun Dr Ismail',
            'city' => 'Kuala Lumpur',
            'state' => 'Kuala Lumpur',
            'postcode' => '60000',
            'item_name' => 'Ceramic dinner set',
            'declared_weight_g' => 4200,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 25,
        ];
    }

    public function test_a_customer_creates_a_priced_order_with_a_tracking_number()
    {
        $customer = User::factory()->create(['name' => 'Aisyah Rahman', 'phone' => '+60123456789']);
        $branch = Branch::factory()->create();

        $order = app(CreateOrder::class)->handle($customer, $this->input($branch));

        $this->assertMatchesRegularExpression('/^KT[0-9A-HJKMNP-TV-Z]{8}$/', $order->tracking_number);
        $this->assertSame(OrderStatus::Created, $order->fresh()?->status);
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame($branch->id, $order->branch_id);
        $this->assertSame('Aisyah Rahman', $order->sender_name);
        $this->assertSame('+60123456789', $order->sender_phone);
        $this->assertSame(MalaysianState::KualaLumpur, $order->state);
        $this->assertSame(6000, $order->chargeable_weight_g);
        $this->assertSame(1800, $order->estimated_price_sen);
        $this->assertNull($order->final_price_sen);

        $event = $order->statusEvents()->sole();
        $this->assertNull($event->from_status);
        $this->assertSame(OrderStatus::Created, $event->to_status);
        $this->assertSame($customer->id, $event->actor_id);
    }

    public function test_system_fields_cannot_be_mass_assigned_from_input()
    {
        $customer = User::factory()->create();
        $other = User::factory()->create();
        $driver = User::factory()->driver()->create();

        $order = app(CreateOrder::class)->handle($customer, [
            ...$this->input(Branch::factory()->create()),
            'status' => 'delivered',
            'customer_id' => $other->id,
            'driver_id' => $driver->id,
            'tracking_number' => 'KT00000000',
            'estimated_price_sen' => 1,
            'final_price_sen' => 1,
            'chargeable_weight_g' => 1,
            'sender_name' => 'Someone Else',
        ])->fresh();

        $this->assertNotNull($order);
        $this->assertSame(OrderStatus::Created, $order->status);
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertNull($order->driver_id);
        $this->assertNotSame('KT00000000', $order->tracking_number);
        $this->assertSame(1800, $order->estimated_price_sen);
        $this->assertNull($order->final_price_sen);
        $this->assertSame(6000, $order->chargeable_weight_g);
        $this->assertSame($customer->name, $order->sender_name);
    }

    public function test_the_customer_needs_a_phone_number_to_send_a_parcel()
    {
        $customer = User::factory()->create(['phone' => null]);

        $this->expectException(ValidationException::class);

        app(CreateOrder::class)->handle($customer, $this->input(Branch::factory()->create()));
    }
}
