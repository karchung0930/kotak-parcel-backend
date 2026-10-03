<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\RecordDropOff;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRateCards;
use Tests\TestCase;

class RecordDropOffTest extends TestCase
{
    use CreatesRateCards;
    use RefreshDatabase;

    public function test_staff_weigh_the_parcel_and_set_the_final_price()
    {
        $counter = Branch::factory()->create();
        $staff = User::factory()->staff($counter)->create();
        $order = Order::factory()->create([
            'declared_weight_g' => 4200, 'length_cm' => 40, 'width_cm' => 30, 'height_cm' => 25,
        ]);

        $order = app(RecordDropOff::class)->handle($order, $staff, 7300);

        $this->assertSame(OrderStatus::DroppedOff, $order->status);
        $this->assertSame(7300, $order->measured_weight_g);
        $this->assertSame(7300, $order->chargeable_weight_g);
        // 7.3 kg starts 8 kg: RM 8.00 + 7 x RM 2.00.
        $this->assertSame(2200, $order->final_price_sen);
        $this->assertNotNull($order->dropped_off_at);
        // The parcel is now at the branch where it was handed in.
        $this->assertSame($counter->id, $order->branch_id);
        $this->assertSame($counter->id, $order->latestStatusEvent()->firstOrFail()->branch_id);
    }

    public function test_corrected_dimensions_are_used_for_the_price()
    {
        $staff = User::factory()->staff()->create();
        $order = Order::factory()->create([
            'declared_weight_g' => 1000, 'length_cm' => 20, 'width_cm' => 20, 'height_cm' => 10,
        ]);

        $order = app(RecordDropOff::class)->handle($order, $staff, 1000, lengthCm: 50, widthCm: 40, heightCm: 30);

        $this->assertSame([50, 40, 30], [$order->length_cm, $order->width_cm, $order->height_cm]);
        $this->assertSame(12000, $order->chargeable_weight_g);
        $this->assertSame(3000, $order->final_price_sen);
    }

    public function test_admins_without_a_branch_keep_the_chosen_branch()
    {
        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create();

        $updated = app(RecordDropOff::class)->handle($order, $admin, 1500);

        $this->assertSame($order->branch_id, $updated->branch_id);
    }

    public function test_the_final_price_uses_the_rates_in_effect_at_drop_off_from_the_counter_s_branch()
    {
        $kualaLumpur = Branch::factory()->create(['state' => 'Kuala Lumpur']);
        $order = app(CreateOrder::class)->handle(User::factory()->create(), [
            'branch_id' => $kualaLumpur->id,
            'receiver_name' => 'Brian Teo',
            'receiver_phone' => '+60131234510',
            'address_line1' => 'Lot 7, Jalan Lintas',
            'city' => 'Kota Kinabalu',
            'state' => 'Sabah',
            'postcode' => '88300',
            'item_name' => 'Hiking boots',
            'declared_weight_g' => 2300,
            'length_cm' => 40, 'width_cm' => 30, 'height_cm' => 15,
        ]);
        $standardId = $order->estimated_rate_card_id;
        // 3.6 kg by size, at RM 8.00 + 3 x RM 2.00 on the Standard rates.
        $this->assertSame(1400, $order->estimated_price_sen);

        $zoned = $this->zoneRates(now()->addDay());
        $this->travel(2)->days();

        // Handed in at a Sabah counter instead: Within Sabah & Labuan, 3.6 kg
        // is 1 kg over the 3 kg band (RM 13.00 + RM 2.50).
        $sabahCounter = Branch::factory()->create(['state' => 'Sabah', 'city' => 'Kota Kinabalu']);
        $order = app(RecordDropOff::class)->handle($order, User::factory()->staff($sabahCounter)->create(), 2300);

        $this->assertSame(3600, $order->chargeable_weight_g);
        $this->assertSame(1550, $order->final_price_sen);
        $this->assertSame($zoned->id, $order->final_rate_card_id);
        // The online estimate keeps the rates it was given.
        $this->assertSame($standardId, $order->estimated_rate_card_id);
        $this->assertSame(1400, $order->estimated_price_sen);
    }

    public function test_a_parcel_can_only_be_dropped_off_once()
    {
        $staff = User::factory()->staff()->create();
        $order = Order::factory()->droppedOff()->create();

        $this->expectException(InvalidStatusTransition::class);

        app(RecordDropOff::class)->handle($order, $staff, 1500);
    }
}
