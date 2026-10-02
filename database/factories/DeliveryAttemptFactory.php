<?php

namespace Database\Factories;

use App\Enums\DeliveryFailureReason;
use App\Enums\DeliveryOutcome;
use App\Enums\OrderStatus;
use App\Models\DeliveryAttempt;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Prefer Order::factory()->delivered() / ->deliveryFailed(), which create the attempt with its order.
 *
 * @extends Factory<DeliveryAttempt>
 */
class DeliveryAttemptFactory extends Factory
{
    /**
     * Define the model's default state (a successful delivery).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory()->pickedUp()->state([
                'status' => OrderStatus::Delivered,
                'delivered_at' => now(),
            ]),
            'driver_id' => fn (array $attributes) => Order::query()->whereKey($attributes['order_id'])->firstOrFail()->driver_id,
            'outcome' => DeliveryOutcome::Delivered,
            'recipient_name' => fake()->name(),
            'photo_path' => null,
            'failure_reason' => null,
            'note' => null,
            'attempted_at' => now(),
        ];
    }

    /**
     * Indicate that the delivery attempt failed.
     */
    public function failed(DeliveryFailureReason $reason = DeliveryFailureReason::RecipientUnavailable): static
    {
        return $this->state(fn (array $attributes) => [
            'outcome' => DeliveryOutcome::Failed,
            'recipient_name' => null,
            'failure_reason' => $reason,
        ]);
    }
}
