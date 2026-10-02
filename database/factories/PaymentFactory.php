<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Prefer Order::factory()->paid(), which creates the payment with its order.
 *
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state (a cash payment for the order's final price).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory()->droppedOff()->state([
                'status' => OrderStatus::Paid,
                'paid_at' => now(),
            ]),
            'amount_sen' => fn (array $attributes) => $this->order($attributes)->final_price_sen,
            'method' => PaymentMethod::Cash,
            'reference' => null,
            'receipt_number' => fn (array $attributes) => Payment::receiptNumberFor($this->order($attributes), now()),
            'branch_id' => fn (array $attributes) => $this->order($attributes)->branch_id,
            'received_by' => fn (array $attributes) => User::factory()->staff()->state(['branch_id' => $attributes['branch_id']]),
            'paid_at' => now(),
        ];
    }

    /**
     * Indicate that the customer paid by card.
     */
    public function card(): static
    {
        return $this->state(fn (array $attributes) => [
            'method' => PaymentMethod::Card,
            'reference' => strtoupper(fake()->bothify('######')),
        ]);
    }

    /**
     * Get the order being paid for.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function order(array $attributes): Order
    {
        return Order::query()->whereKey($attributes['order_id'])->firstOrFail();
    }
}
