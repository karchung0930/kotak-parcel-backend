<?php

namespace Tests\Feature\Staff;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DropOffTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->branch = Branch::factory()->create();
        $this->staff = User::factory()->staff($this->branch)->create();
    }

    public function test_staff_weigh_the_parcel_and_the_final_price_is_set()
    {
        // 4.2 kg in a 40 x 30 x 25 cm box is charged as its 6 kg volumetric weight: RM 18.00.
        $order = Order::factory()->created()->create([
            'declared_weight_g' => 4000,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 25,
        ]);

        $this->actingAs($this->staff)
            ->post(route('staff.orders.drop-off', $order), ['measured_weight_g' => 4200])
            ->assertRedirect(route('staff.orders.show', $order))
            ->assertInertiaFlash('toast.type', 'success')
            ->assertInertiaFlash('toast.message', 'Parcel received. The final price is RM 18.00.');

        $order->refresh();
        $this->assertSame(OrderStatus::DroppedOff, $order->status);
        $this->assertSame(4200, $order->measured_weight_g);
        $this->assertSame(6000, $order->chargeable_weight_g);
        $this->assertSame(1800, $order->final_price_sen);
        $this->assertNotNull($order->dropped_off_at);
    }

    public function test_the_parcel_moves_to_the_branch_where_it_was_dropped_off()
    {
        $order = Order::factory()->created()->create();
        $this->assertNotSame($this->branch->id, $order->branch_id);

        $this->actingAs($this->staff)
            ->post(route('staff.orders.drop-off', $order), ['measured_weight_g' => 1000]);

        $this->assertSame($this->branch->id, $order->refresh()->branch_id);
        $this->assertSame($this->branch->id, $order->latestStatusEvent()->firstOrFail()->branch_id);
    }

    public function test_staff_can_correct_the_dimensions()
    {
        $order = Order::factory()->created()->create([
            'declared_weight_g' => 4000,
            'length_cm' => 10,
            'width_cm' => 10,
            'height_cm' => 10,
        ]);

        $this->actingAs($this->staff)->post(route('staff.orders.drop-off', $order), [
            'measured_weight_g' => 4200,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 25,
        ])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame([40, 30, 25], [$order->length_cm, $order->width_cm, $order->height_cm]);
        $this->assertSame(6000, $order->chargeable_weight_g);
        $this->assertSame(1800, $order->final_price_sen);
    }

    public function test_dimensions_that_are_not_corrected_are_kept()
    {
        $order = Order::factory()->created()->create(['length_cm' => 20, 'width_cm' => 15, 'height_cm' => 10]);

        $this->actingAs($this->staff)->post(route('staff.orders.drop-off', $order), [
            'measured_weight_g' => 500,
            'length_cm' => 22,
            'width_cm' => null,
        ])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame([22, 15, 10], [$order->length_cm, $order->width_cm, $order->height_cm]);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidMeasurements(): array
    {
        return [
            'missing weight' => [[], 'measured_weight_g'],
            'zero weight' => [['measured_weight_g' => 0], 'measured_weight_g'],
            'over the weight limit' => [['measured_weight_g' => 30001], 'measured_weight_g'],
            'fractional weight' => [['measured_weight_g' => 1.5], 'measured_weight_g'],
            'text weight' => [['measured_weight_g' => 'heavy'], 'measured_weight_g'],
            'zero length' => [['measured_weight_g' => 1000, 'length_cm' => 0], 'length_cm'],
            'over the size limit' => [['measured_weight_g' => 1000, 'height_cm' => 151], 'height_cm'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[DataProvider('invalidMeasurements')]
    public function test_measurements_are_validated(array $input, string $field)
    {
        $order = Order::factory()->created()->create();

        $this->actingAs($this->staff)
            ->post(route('staff.orders.drop-off', $order), $input)
            ->assertSessionHasErrors($field);

        $this->assertSame(OrderStatus::Created, $order->refresh()->status);
    }

    public function test_a_parcel_cannot_be_weighed_twice()
    {
        $order = Order::factory()->droppedOff()->create();
        $price = $order->final_price_sen;

        $this->actingAs($this->staff)
            ->from(route('staff.orders.show', $order))
            ->post(route('staff.orders.drop-off', $order), ['measured_weight_g' => 29000])
            ->assertRedirect(route('staff.orders.show', $order))
            ->assertSessionHasErrors('status')
            ->assertInertiaFlash('toast.type', 'error');

        $this->assertSame($price, $order->refresh()->final_price_sen);
    }

    public function test_staff_cancel_a_weighed_parcel_when_the_customer_refuses_the_price()
    {
        $order = Order::factory()->droppedOff()->create();

        $this->actingAs($this->staff)
            ->post(route('staff.orders.cancel', $order), ['reason' => 'Customer found a cheaper courier.'])
            ->assertRedirect(route('staff.orders.show', $order))
            ->assertInertiaFlash('toast.type', 'success');

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame('Customer found a cheaper courier.', $order->latestStatusEvent()->firstOrFail()->note);
    }

    public function test_the_refused_price_is_recorded_when_no_reason_is_given()
    {
        $order = Order::factory()->droppedOff()->create();

        $this->actingAs($this->staff)->post(route('staff.orders.cancel', $order));

        $this->assertSame('Customer declined the final price.', $order->latestStatusEvent()->firstOrFail()->note);
    }

    public function test_the_cancellation_reason_has_a_length_limit()
    {
        $order = Order::factory()->droppedOff()->create();

        $this->actingAs($this->staff)
            ->post(route('staff.orders.cancel', $order), ['reason' => str_repeat('a', 256)])
            ->assertSessionHasErrors('reason');

        $this->assertSame(OrderStatus::DroppedOff, $order->refresh()->status);
    }

    public function test_staff_can_only_cancel_parcels_waiting_for_payment()
    {
        foreach ([Order::factory()->created()->create(), Order::factory()->paid()->create()] as $order) {
            $status = $order->status;

            $this->actingAs($this->staff)
                ->post(route('staff.orders.cancel', $order))
                ->assertForbidden();

            $this->assertSame($status, $order->refresh()->status);
        }
    }
}
