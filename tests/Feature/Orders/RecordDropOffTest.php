<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\RecordDropOff;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordDropOffTest extends TestCase
{
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

    public function test_a_parcel_can_only_be_dropped_off_once()
    {
        $staff = User::factory()->staff()->create();
        $order = Order::factory()->droppedOff()->create();

        $this->expectException(InvalidStatusTransition::class);

        app(RecordDropOff::class)->handle($order, $staff, 1500);
    }
}
