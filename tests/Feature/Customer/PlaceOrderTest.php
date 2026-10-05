<?php

namespace Tests\Feature\Customer;

use App\Enums\MalaysianState;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Support\PriceCalculator;
use App\Support\RateCards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesRateCards;
use Tests\TestCase;

class PlaceOrderTest extends TestCase
{
    use CreatesRateCards;
    use RefreshDatabase;

    private User $customer;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        // Pages are checked through their Inertia props, not the built assets.
        $this->withoutVite();

        $this->customer = User::factory()->create(['name' => 'Aisyah Rahman', 'phone' => '+60123456789']);
        $this->branch = Branch::factory()->create();
    }

    /**
     * Valid order input, as the form sends it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return [
            'branch_id' => $this->branch->id,
            'receiver_name' => 'Daniel Lim',
            'receiver_phone' => '012-778 8990',
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
            ...$overrides,
        ];
    }

    public function test_the_form_offers_active_branches_states_pricing_and_the_sender()
    {
        Branch::factory()->inactive()->create();

        $this->actingAs($this->customer)
            ->get(route('orders.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('orders/Create')
                ->has('branches', 1)
                ->where('branches.0.id', $this->branch->id)
                ->has('states', count(MalaysianState::cases()))
                ->where('states.0', ['value' => 'Johor', 'label' => 'Johor'])
                ->where('pricing', app(PriceCalculator::class)->publicRules(app(RateCards::class)->current()))
                ->where('sender', ['name' => 'Aisyah Rahman', 'phone' => '+60123456789']));
    }

    public function test_customers_create_an_order_and_see_its_tracking_number()
    {
        $response = $this->actingAs($this->customer)->post(route('orders.store'), $this->input());

        $order = Order::query()->sole();

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('orders.show', $order))
            ->assertInertiaFlash('toast.type', 'success')
            ->assertInertiaFlash('toast.message', "Order {$order->formatted_tracking_number} created. Drop your parcel off at the branch to send it.");

        $this->assertSame(OrderStatus::Created, $order->status);
        $this->assertSame($this->customer->id, $order->customer_id);
        $this->assertSame($this->branch->id, $order->branch_id);
        $this->assertSame('Aisyah Rahman', $order->sender_name);
        $this->assertSame('+60123456789', $order->sender_phone);
        $this->assertSame('Daniel Lim', $order->receiver_name);
        $this->assertSame('+60127788990', $order->receiver_phone);
        $this->assertSame(MalaysianState::KualaLumpur, $order->state);
        $this->assertSame('60000', $order->postcode);
        // 4.2 kg in a 40 x 30 x 25 cm box is charged as 6 kg: RM 18.00.
        $this->assertSame(6000, $order->chargeable_weight_g);
        $this->assertSame(1800, $order->estimated_price_sen);
        $this->assertSame(OrderStatus::Created, $order->statusEvents()->sole()->to_status);
    }

    /**
     * @return array<string, array{int, int, int, int}>
     */
    public static function parcels(): array
    {
        return [
            'light and small' => [300, 20, 15, 5],
            'exactly 1 kg' => [1000, 10, 10, 10],
            'heavier than it is big' => [12500, 30, 30, 20],
            'bigger than it is heavy' => [2000, 100, 60, 50],
            'at the limits' => [30000, 150, 150, 150],
        ];
    }

    #[DataProvider('parcels')]
    public function test_the_estimate_is_the_price_calculator_price(int $weight, int $length, int $width, int $height)
    {
        $this->actingAs($this->customer)
            ->post(route('orders.store'), $this->input([
                'declared_weight_g' => $weight,
                'length_cm' => $length,
                'width_cm' => $width,
                'height_cm' => $height,
            ]))
            ->assertSessionHasNoErrors();

        $quote = app(PriceCalculator::class)->quote(
            app(RateCards::class)->current(), $this->branch->state, MalaysianState::KualaLumpur, $weight, $length, $width, $height,
        );
        $order = Order::query()->sole();

        $this->assertSame($quote->chargeableG, $order->chargeable_weight_g);
        $this->assertSame($quote->priceSen, $order->estimated_price_sen);
        $this->assertSame($quote->rateCardId, $order->estimated_rate_card_id);
    }

    public function test_the_estimate_runs_from_the_chosen_branch_to_the_receiver_s_state()
    {
        $card = $this->zoneRates();

        $this->actingAs($this->customer)
            ->post(route('orders.store'), $this->input([
                'state' => 'Sabah',
                'city' => 'Kota Kinabalu',
                'postcode' => '88300',
                'declared_weight_g' => 1600,
                'length_cm' => 35,
                'width_cm' => 25,
                'height_cm' => 10,
            ]))
            ->assertSessionHasNoErrors();

        $order = Order::query()->sole();

        // Peninsular Malaysia to Sabah & Labuan: 1.75 kg by size is in the 2 kg band.
        $this->assertSame(1750, $order->chargeable_weight_g);
        $this->assertSame(1700, $order->estimated_price_sen);
        $this->assertSame($card->id, $order->estimated_rate_card_id);
        $this->assertNull($order->final_rate_card_id);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidInput(): array
    {
        return [
            'foreign receiver phone' => [['receiver_phone' => '+6591234567'], 'receiver_phone'],
            'foreign receiver phone with its country' => [['receiver_phone' => '+6591234567', 'receiver_phone_country' => 'SG'], 'receiver_phone'],
            'foreign receiver phone with a country field' => [['receiver_phone' => '+6591234567', 'MY' => 'SG'], 'receiver_phone'],
            '1-300 receiver phone' => [['receiver_phone' => '1-300-88-1234'], 'receiver_phone'],
            'unallocated receiver phone' => [['receiver_phone' => '010-123 4567'], 'receiver_phone'],
            'missing receiver phone' => [['receiver_phone' => ''], 'receiver_phone'],
            'missing receiver name' => [['receiver_name' => ''], 'receiver_name'],
            'receiver email without a domain' => [['receiver_email' => 'daniel@'], 'receiver_email'],
            'receiver email with a space' => [['receiver_email' => 'daniel lim@example.com'], 'receiver_email'],
            'two receiver emails' => [['receiver_email' => 'daniel@example.com, mei@example.com'], 'receiver_email'],
            'receiver email over 255 characters' => [['receiver_email' => str_repeat('d', 244).'@example.com'], 'receiver_email'],
            'receiver email as a list' => [['receiver_email' => ['daniel@example.com']], 'receiver_email'],
            // Mail servers refuse or bounce these, and nobody confirms the receiver's address.
            'receiver email with no top-level domain' => [['receiver_email' => 'daniel@gmail'], 'receiver_email'],
            'receiver email at localhost' => [['receiver_email' => 'daniel@localhost'], 'receiver_email'],
            'receiver email with a comment' => [['receiver_email' => 'daniel(comment)@example.com'], 'receiver_email'],
            'receiver email with a quoted name' => [['receiver_email' => '"daniel lim"@example.com'], 'receiver_email'],
            'receiver email at an IP address' => [['receiver_email' => 'daniel@[127.0.0.1]'], 'receiver_email'],
            'receiver email with accents' => [['receiver_email' => 'dánïel@example.com'], 'receiver_email'],
            'missing address' => [['address_line1' => ''], 'address_line1'],
            'missing city' => [['city' => ''], 'city'],
            'city over two lines' => [['city' => "Klang\n\n# Urgent"], 'city'],
            'city with a link' => [['city' => 'Klang [Sign in](https://example.com)'], 'city'],
            'city with a tag' => [['city' => 'Klang <b>'], 'city'],
            'city with a pipe' => [['city' => 'Kuala | Lumpur'], 'city'],
            'unknown state' => [['state' => 'Singapore'], 'state'],
            'four digit postcode' => [['postcode' => '4700'], 'postcode'],
            'six digit postcode' => [['postcode' => '470001'], 'postcode'],
            'postcode with letters' => [['postcode' => '47a00'], 'postcode'],
            'missing item name' => [['item_name' => ''], 'item_name'],
            'zero weight' => [['declared_weight_g' => 0], 'declared_weight_g'],
            'fractional weight' => [['declared_weight_g' => 4200.5], 'declared_weight_g'],
            'too heavy' => [['declared_weight_g' => 30001], 'declared_weight_g'],
            'zero length' => [['length_cm' => 0], 'length_cm'],
            'too long' => [['length_cm' => 151], 'length_cm'],
            'too wide' => [['width_cm' => 151], 'width_cm'],
            'too tall' => [['height_cm' => 151], 'height_cm'],
            'text dimension' => [['height_cm' => 'big'], 'height_cm'],
            'missing branch' => [['branch_id' => null], 'branch_id'],
            'unknown branch' => [['branch_id' => 999999], 'branch_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidInput')]
    public function test_invalid_orders_are_rejected(array $overrides, string $field)
    {
        $this->actingAs($this->customer)
            ->from(route('orders.create'))
            ->post(route('orders.store'), $this->input($overrides))
            ->assertRedirect(route('orders.create'))
            ->assertSessionHasErrors($field);

        $this->assertSame(0, Order::query()->count());
    }

    public function test_limit_errors_are_explained_in_plain_units()
    {
        $this->actingAs($this->customer)
            ->post(route('orders.store'), $this->input(['declared_weight_g' => 30001, 'length_cm' => 151, 'receiver_phone' => '12345']))
            ->assertSessionHasErrors([
                'declared_weight_g' => 'We accept parcels up to 30 kg.',
                'length_cm' => 'Each side of the parcel can be up to 150 cm.',
                'receiver_phone' => 'Enter a valid Malaysian mobile or landline number.',
            ]);
    }

    public function test_the_receiver_email_is_optional()
    {
        $this->actingAs($this->customer)->post(route('orders.store'), $this->input())->assertSessionHasNoErrors();
        $this->actingAs($this->customer)->post(route('orders.store'), $this->input(['receiver_email' => '']))->assertSessionHasNoErrors();
        $this->actingAs($this->customer)->post(route('orders.store'), $this->input(['receiver_email' => ' daniel.lim@example.com ']))->assertSessionHasNoErrors();
        $this->actingAs($this->customer)->post(route('orders.store'), $this->input(['receiver_email' => "daniel.o'neil+parcels@mail.example.com.my"]))->assertSessionHasNoErrors();

        $this->assertSame(
            [null, null, 'daniel.lim@example.com', "daniel.o'neil+parcels@mail.example.com.my"],
            Order::query()->orderBy('id')->pluck('receiver_email')->all(),
        );
    }

    public function test_an_invalid_receiver_email_is_explained()
    {
        $this->actingAs($this->customer)
            ->post(route('orders.store'), $this->input(['receiver_email' => 'daniel@']))
            ->assertSessionHasErrors(['receiver_email' => 'Enter a valid email address, or leave it empty.']);

        $this->actingAs($this->customer)
            ->post(route('orders.store'), $this->input(['receiver_email' => str_repeat('d', 244).'@example.com']))
            ->assertSessionHasErrors(['receiver_email' => "The receiver's email field must not be greater than 255 characters."]);
    }

    public function test_a_city_with_markup_is_explained()
    {
        $this->actingAs($this->customer)
            ->post(route('orders.store'), $this->input(['city' => 'Klang <b>']))
            ->assertSessionHasErrors(['city' => "Remove the characters <\u{00A0}>\u{00A0}[\u{00A0}]\u{00A0}| from the city."]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function receiverPhones(): array
    {
        return [
            'mobile as the form sends it' => ['+60127788990', '+60127788990'],
            'mobile without the trunk 0' => ['12-778 8990', '+60127788990'],
            'Klang Valley landline' => ['03-7877 1203', '+60378771203'],
            'east coast landline' => ['+60 9-748 1234', '+6097481234'],
        ];
    }

    #[DataProvider('receiverPhones')]
    public function test_the_receiver_may_have_a_mobile_or_a_landline(string $phone, string $stored)
    {
        $this->actingAs($this->customer)
            ->post(route('orders.store'), $this->input(['receiver_phone' => $phone]))
            ->assertSessionHasNoErrors();

        $this->assertSame($stored, Order::query()->sole()->receiver_phone);
    }

    public function test_parcels_cannot_be_sent_from_an_inactive_branch()
    {
        $closed = Branch::factory()->inactive()->create();

        $this->actingAs($this->customer)
            ->post(route('orders.store'), $this->input(['branch_id' => $closed->id]))
            ->assertSessionHasErrors(['branch_id' => 'Choose a branch that is open for drop-off.']);

        $this->assertSame(0, Order::query()->count());
    }

    public function test_the_server_sets_the_sender_status_and_prices()
    {
        $other = User::factory()->create();

        $this->actingAs($this->customer)
            ->post(route('orders.store'), $this->input([
                'customer_id' => $other->id,
                'sender_name' => 'Someone Else',
                'sender_phone' => '+60199999999',
                'status' => 'delivered',
                'tracking_number' => 'KT00000000',
                'chargeable_weight_g' => 1,
                'estimated_price_sen' => 1,
                'final_price_sen' => 1,
                'driver_id' => User::factory()->driver()->create()->id,
            ]))
            ->assertSessionHasNoErrors();

        $order = Order::query()->sole();

        $this->assertSame($this->customer->id, $order->customer_id);
        $this->assertSame('Aisyah Rahman', $order->sender_name);
        $this->assertSame('+60123456789', $order->sender_phone);
        $this->assertSame(OrderStatus::Created, $order->status);
        $this->assertNotSame('KT00000000', $order->tracking_number);
        $this->assertSame(6000, $order->chargeable_weight_g);
        $this->assertSame(1800, $order->estimated_price_sen);
        $this->assertNull($order->final_price_sen);
        $this->assertNull($order->driver_id);
    }

    public function test_customers_without_a_mobile_number_are_asked_to_add_one()
    {
        $this->customer->forceFill(['phone' => null])->save();

        $this->actingAs($this->customer)
            ->post(route('orders.store'), $this->input())
            ->assertSessionHasErrors('phone');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_unverified_customers_cannot_create_orders()
    {
        $unverified = User::factory()->unverified()->create();

        $this->actingAs($unverified)->get(route('orders.create'))->assertRedirect(route('verification.notice'));
        $this->actingAs($unverified)->post(route('orders.store'), $this->input())->assertRedirect(route('verification.notice'));

        $this->assertSame(0, Order::query()->count());
    }

    public function test_guests_cannot_create_orders()
    {
        $this->post(route('orders.store'), $this->input())->assertRedirect(route('login'));

        $this->assertSame(0, Order::query()->count());
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function otherRoles(): array
    {
        return [
            'staff' => [Role::Staff],
            'admin' => [Role::Admin],
            'driver' => [Role::Driver],
        ];
    }

    #[DataProvider('otherRoles')]
    public function test_only_customers_can_create_orders(Role $role)
    {
        $this->actingAs(User::factory()->create(['role' => $role]))
            ->post(route('orders.store'), $this->input())
            ->assertForbidden();

        $this->assertSame(0, Order::query()->count());
    }

    public function test_order_creation_is_rate_limited_per_customer()
    {
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($this->customer)->post(route('orders.store'))->assertSessionHasErrors();
        }

        $this->actingAs($this->customer)->post(route('orders.store'), $this->input())->assertTooManyRequests();

        $this->assertSame(0, Order::query()->count());

        // Other customers have their own allowance.
        $this->actingAs(User::factory()->create())
            ->post(route('orders.store'), $this->input())
            ->assertSessionHasNoErrors();
    }
}
