<?php

namespace Tests\Feature\Staff;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $staff;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->branch = Branch::factory()->create();
        $this->staff = User::factory()->staff($this->branch)->create();
        $this->order = Order::factory()->droppedOff()->for($this->branch)->create();
    }

    public function test_staff_take_a_cash_payment_and_are_shown_the_receipt()
    {
        $response = $this->actingAs($this->staff)->post(route('staff.orders.payment', $this->order), [
            'method' => 'cash',
            'amount_sen' => $this->order->final_price_sen,
        ]);

        $payment = Payment::query()->sole();
        $response->assertRedirect(route('staff.payments.receipt', $payment))
            ->assertInertiaFlash('toast.type', 'success');

        $this->assertSame($this->order->id, $payment->order_id);
        $this->assertSame($this->order->final_price_sen, $payment->amount_sen);
        $this->assertSame(PaymentMethod::Cash, $payment->method);
        $this->assertNull($payment->reference);
        $this->assertSame($this->staff->id, $payment->received_by);
        $this->assertSame($this->branch->id, $payment->branch_id);
        $this->assertSame(OrderStatus::Paid, $this->order->refresh()->status);
    }

    public function test_card_payments_record_the_terminal_approval_code()
    {
        $this->actingAs($this->staff)->post(route('staff.orders.payment', $this->order), [
            'method' => 'card',
            'amount_sen' => $this->order->final_price_sen,
            'reference' => 'A1B2C3',
        ])->assertSessionHasNoErrors();

        $payment = Payment::query()->sole();
        $this->assertSame(PaymentMethod::Card, $payment->method);
        $this->assertSame('A1B2C3', $payment->reference);
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function invalidCardReferences(): array
    {
        return [
            'missing' => [null],
            'card number' => ['4111111111111111'],
            'card number with spaces' => ['4111 1111 1111 1111'],
            'too short' => ['12'],
        ];
    }

    #[DataProvider('invalidCardReferences')]
    public function test_card_payments_need_an_approval_code_and_never_a_card_number(?string $reference)
    {
        $this->actingAs($this->staff)->post(route('staff.orders.payment', $this->order), [
            'method' => 'card',
            'amount_sen' => $this->order->final_price_sen,
            'reference' => $reference,
        ])->assertSessionHasErrors('reference');

        $this->assertSame(0, Payment::count());
        $this->assertSame(OrderStatus::DroppedOff, $this->order->refresh()->status);
    }

    public function test_a_reference_sent_with_a_cash_payment_is_ignored()
    {
        $this->actingAs($this->staff)->post(route('staff.orders.payment', $this->order), [
            'method' => 'cash',
            'amount_sen' => $this->order->final_price_sen,
            'reference' => '4111111111111111',
        ])->assertSessionHasNoErrors();

        $this->assertNull(Payment::query()->sole()->reference);
    }

    public function test_a_malformed_reference_sent_with_a_cash_payment_is_ignored()
    {
        $this->actingAs($this->staff)->post(route('staff.orders.payment', $this->order), [
            'method' => 'cash',
            'amount_sen' => $this->order->final_price_sen,
            'reference' => ['4111111111111111'],
        ])->assertSessionHasNoErrors();

        $this->assertNull(Payment::query()->sole()->reference);
        $this->assertSame(OrderStatus::Paid, $this->order->refresh()->status);
    }

    public function test_the_amount_must_equal_the_final_price()
    {
        $this->actingAs($this->staff)->post(route('staff.orders.payment', $this->order), [
            'method' => 'cash',
            'amount_sen' => (int) $this->order->final_price_sen - 1,
        ])->assertSessionHasErrors('amount_sen');

        $this->assertSame(0, Payment::count());
        $this->assertSame(OrderStatus::DroppedOff, $this->order->refresh()->status);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayments(): array
    {
        return [
            'unknown method' => [['method' => 'crypto', 'amount_sen' => 800], 'method'],
            'missing method' => [['amount_sen' => 800], 'method'],
            'missing amount' => [['method' => 'cash'], 'amount_sen'],
            'amount in ringgit' => [['method' => 'cash', 'amount_sen' => '18.00'], 'amount_sen'],
            'negative amount' => [['method' => 'cash', 'amount_sen' => -800], 'amount_sen'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[DataProvider('invalidPayments')]
    public function test_payment_input_is_validated(array $input, string $field)
    {
        $this->actingAs($this->staff)
            ->post(route('staff.orders.payment', $this->order), $input)
            ->assertSessionHasErrors($field);

        $this->assertSame(0, Payment::count());
    }

    public function test_an_order_cannot_be_paid_twice()
    {
        $payment = ['method' => 'cash', 'amount_sen' => $this->order->final_price_sen];

        $this->actingAs($this->staff)
            ->post(route('staff.orders.payment', $this->order), $payment)
            ->assertSessionHasNoErrors();

        $this->actingAs($this->staff)
            ->from(route('staff.orders.show', $this->order))
            ->post(route('staff.orders.payment', $this->order), $payment)
            ->assertRedirect(route('staff.orders.show', $this->order))
            ->assertSessionHasErrors('status')
            ->assertInertiaFlash('toast.type', 'error');

        $this->assertSame(1, Payment::count());
    }

    public function test_api_clients_get_a_conflict_for_a_second_payment()
    {
        $order = Order::factory()->paid()->create();

        $this->actingAs($this->staff)
            ->postJson(route('staff.orders.payment', $order), [
                'method' => 'cash',
                'amount_sen' => $order->final_price_sen,
            ])
            ->assertConflict();

        $this->assertSame(1, Payment::count());
    }

    public function test_a_parcel_must_be_weighed_before_it_is_paid_for()
    {
        $order = Order::factory()->created()->create();

        $this->actingAs($this->staff)->post(route('staff.orders.payment', $order), [
            'method' => 'cash',
            'amount_sen' => $order->estimated_price_sen,
        ])->assertSessionHasErrors('status');

        $this->assertSame(0, Payment::count());
        $this->assertSame(OrderStatus::Created, $order->refresh()->status);
    }

    public function test_the_receipt_shows_the_payment_and_its_parcel()
    {
        $order = Order::factory()->paid()->create(['receiver_email' => 'daniel@example.com']);
        $payment = $order->payment()->sole();

        $this->actingAs($this->staff)
            ->get(route('staff.payments.receipt', $payment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/Receipt')
                ->where('payment.receipt_number', $payment->receipt_number)
                ->where('payment.amount_sen', $order->final_price_sen)
                ->where('payment.method.value', 'cash')
                ->where('payment.order.tracking_number', $order->formatted_tracking_number)
                ->where('payment.order.receiver_name', $order->receiver_name)
                // The receipt prints none of the contact details.
                ->missing('payment.order.receiver_email')
                ->missing('payment.order.receiver_phone')
                ->missing('payment.order.sender_phone')
                ->missing('payment.order.address_line1')
                ->where('payment.branch.id', $payment->branch_id)
                ->where('payment.received_by.id', $payment->received_by));
    }
}
