<?php

namespace App\Actions\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\OrderStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordPayment
{
    /**
     * Create a new action instance.
     */
    public function __construct(
        private OrderStatusService $statuses,
    ) {}

    /**
     * Take payment for a weighed parcel at the counter.
     *
     * An order is paid at most once: the status is checked under a row lock and
     * payments.order_id is unique. Card payments need the terminal approval code;
     * card numbers are never collected.
     *
     * @throws ValidationException
     * @throws InvalidStatusTransition
     */
    public function handle(Order $order, User $staff, PaymentMethod $method, int $amountSen, ?string $reference = null): Payment
    {
        $reference = $method === PaymentMethod::Card ? trim((string) $reference) : '';

        if ($method === PaymentMethod::Card && $reference === '') {
            throw ValidationException::withMessages([
                'reference' => 'Enter the approval code from the card terminal.',
            ]);
        }

        return DB::transaction(function () use ($order, $staff, $method, $amountSen, $reference): Payment {
            $order = $order->freshLocked();

            if (! $order->status->canTransitionTo(OrderStatus::Paid)) {
                throw InvalidStatusTransition::between($order->status, OrderStatus::Paid);
            }

            if ($amountSen !== $order->final_price_sen) {
                throw ValidationException::withMessages([
                    'amount_sen' => sprintf('The amount must be exactly RM %s.', number_format(($order->final_price_sen ?? 0) / 100, 2)),
                ]);
            }

            $paidAt = now();

            $payment = $order->payment()->forceCreate([
                'amount_sen' => $amountSen,
                'method' => $method,
                'reference' => $reference !== '' ? $reference : null,
                'receipt_number' => Payment::receiptNumberFor($order, $paidAt),
                'received_by' => $staff->id,
                'branch_id' => $staff->branch_id ?? $order->branch_id,
                'paid_at' => $paidAt,
            ]);

            $this->statuses->transition($order, OrderStatus::Paid, $staff);

            return $payment;
        });
    }
}
