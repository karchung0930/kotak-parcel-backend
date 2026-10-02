<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\RecordPayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecordPaymentTest extends TestCase
{
    use RefreshDatabase;

    private RecordPayment $recordPayment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recordPayment = app(RecordPayment::class);
    }

    public function test_staff_take_a_cash_payment_for_the_final_price()
    {
        $branch = Branch::factory()->create();
        $staff = User::factory()->staff($branch)->create();
        $order = Order::factory()->droppedOff()->create(['tracking_number' => 'KT7Q4M92XD']);

        $payment = $this->recordPayment->handle($order, $staff, PaymentMethod::Cash, (int) $order->final_price_sen, 'ignored');

        $this->assertSame($order->final_price_sen, $payment->amount_sen);
        $this->assertSame(PaymentMethod::Cash, $payment->method);
        $this->assertNull($payment->reference);
        $this->assertSame('RCPT-'.today(config('kotak.timezone'))->format('Ymd').'-7Q4M92XD', $payment->receipt_number);
        $this->assertSame($staff->id, $payment->received_by);
        $this->assertSame($branch->id, $payment->branch_id);

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNotNull($order->paid_at);
    }

    public function test_the_receipt_is_dated_with_the_malaysian_business_day()
    {
        // 07:30 on 30 September in Kuala Lumpur is still 29 September in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-09-30 07:30', 'Asia/Kuala_Lumpur'));
        $order = Order::factory()->droppedOff()->create(['tracking_number' => 'KT7Q4M92XD']);

        $payment = $this->recordPayment->handle($order, User::factory()->staff()->create(), PaymentMethod::Cash, (int) $order->final_price_sen);

        $this->assertSame('RCPT-20260930-7Q4M92XD', $payment->receipt_number);
        // The time itself is still stored in UTC.
        $this->assertSame('2026-09-29 23:30:00', $payment->fresh()?->getRawOriginal('paid_at'));
    }

    public function test_card_payments_record_the_terminal_approval_code()
    {
        $order = Order::factory()->droppedOff()->create();

        $payment = $this->recordPayment->handle(
            $order, User::factory()->staff()->create(), PaymentMethod::Card, (int) $order->final_price_sen, ' 804213 ',
        );

        $this->assertSame(PaymentMethod::Card, $payment->method);
        $this->assertSame('804213', $payment->reference);
    }

    public function test_card_payments_require_an_approval_code()
    {
        $order = Order::factory()->droppedOff()->create();

        try {
            $this->recordPayment->handle($order, User::factory()->staff()->create(), PaymentMethod::Card, (int) $order->final_price_sen);
            $this->fail('A card payment without an approval code must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reference', $e->errors());
        }

        $this->assertSame(0, Payment::count());
    }

    public function test_the_amount_must_match_the_final_price()
    {
        $order = Order::factory()->droppedOff()->create();

        try {
            $this->recordPayment->handle($order, User::factory()->staff()->create(), PaymentMethod::Cash, (int) $order->final_price_sen - 100);
            $this->fail('An amount mismatch must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount_sen', $e->errors());
        }

        $this->assertSame(0, Payment::count());
        $this->assertSame(OrderStatus::DroppedOff, $order->fresh()?->status);
    }

    public function test_an_order_cannot_be_paid_twice()
    {
        $staff = User::factory()->staff()->create();
        $order = Order::factory()->droppedOff()->create();
        $stale = Order::findOrFail($order->id);

        $this->recordPayment->handle($order, $staff, PaymentMethod::Cash, (int) $order->final_price_sen);

        try {
            // A second counter still showing the old "dropped off" order.
            $this->recordPayment->handle($stale, $staff, PaymentMethod::Cash, (int) $stale->final_price_sen);
            $this->fail('A second payment must be rejected.');
        } catch (InvalidStatusTransition) {
            //
        }

        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
    }

    public function test_orders_that_were_not_weighed_cannot_be_paid()
    {
        $order = Order::factory()->create();

        $this->expectException(InvalidStatusTransition::class);

        $this->recordPayment->handle($order, User::factory()->staff()->create(), PaymentMethod::Cash, $order->estimated_price_sen);
    }

    public function test_the_database_also_guarantees_one_payment_per_order()
    {
        $order = Order::factory()->paid()->create();

        $this->expectException(QueryException::class);

        Payment::factory()->for($order)->create(['receipt_number' => 'RCPT-DUPLICATE']);
    }
}
