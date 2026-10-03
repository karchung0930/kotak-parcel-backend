<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\DeliveryAttempt;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Support\PriceCalculator;
use App\Support\TrackingNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Each status state produces a consistent order: the matching weights, prices,
 * timestamps, driver, payment, delivery attempts and tracking history.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state (a newly created order).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $pricing = app(PriceCalculator::class);

        $declared = fake()->numberBetween(200, 15000);
        $length = fake()->numberBetween(10, 60);
        $width = fake()->numberBetween(10, 50);
        $height = fake()->numberBetween(5, 40);
        $chargeable = $pricing->chargeableWeightGrams($declared, $length, $width, $height);

        return [
            'tracking_number' => TrackingNumber::generate(),
            'customer_id' => User::factory(),
            'branch_id' => Branch::factory(),
            'status' => OrderStatus::Created,
            // Snapshot of the customer, as CreateOrder does.
            'sender_name' => fn (array $attributes) => User::query()->whereKey($attributes['customer_id'])->firstOrFail()->name,
            'sender_phone' => fn (array $attributes) => User::query()->whereKey($attributes['customer_id'])->firstOrFail()->phone,
            'receiver_name' => fake()->name(),
            'receiver_phone' => fake()->numerify('+6013%######'),
            'address_line1' => fake()->streetAddress(),
            'address_line2' => null,
            'city' => 'Kuala Lumpur',
            'state' => 'Kuala Lumpur',
            'postcode' => fake()->numerify('5####'),
            'item_name' => fake()->randomElement(['Books', 'Shoes', 'Phone case', 'Ceramic mug set', 'Documents']),
            'declared_weight_g' => $declared,
            'length_cm' => $length,
            'width_cm' => $width,
            'height_cm' => $height,
            'chargeable_weight_g' => $chargeable,
            'estimated_price_sen' => $pricing->priceSen($chargeable),
            // Fixed from the order time and the current limit, as CreateOrder does.
            'drop_off_deadline' => fn (array $attributes) => Order::dropOffDeadlineFor(
                CarbonImmutable::parse($attributes['created_at'] ?? now()),
            )->toDateString(),
        ];
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Order $order): void {
            $from = null;

            foreach (self::historyTo($order->status) as $status) {
                $order->statusEvents()->create(['from_status' => $from, 'to_status' => $status]);
                $from = $status;
            }
        });
    }

    /**
     * A new order waiting to be dropped off.
     */
    public function created(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Created,
        ]);
    }

    /**
     * Weighed at the branch with a final price.
     */
    public function droppedOff(): static
    {
        return $this->state(function (array $attributes) {
            $pricing = app(PriceCalculator::class);
            $measured = max(100, (int) $attributes['declared_weight_g'] + fake()->numberBetween(-100, 300));
            $chargeable = $pricing->chargeableWeightGrams(
                $measured, $attributes['length_cm'], $attributes['width_cm'], $attributes['height_cm'],
            );

            return [
                'status' => OrderStatus::DroppedOff,
                'measured_weight_g' => $measured,
                'chargeable_weight_g' => $chargeable,
                'final_price_sen' => $pricing->priceSen($chargeable),
                'dropped_off_at' => now(),
            ];
        });
    }

    /**
     * Paid at the branch, with its payment record.
     */
    public function paid(): static
    {
        return $this->droppedOff()
            ->state(fn (array $attributes) => [
                'status' => OrderStatus::Paid,
                'paid_at' => now(),
            ])
            ->afterCreating(function (Order $order): void {
                Payment::factory()->for($order)->create();
            });
    }

    /**
     * Scheduled for delivery today with a driver.
     */
    public function assigned(?User $driver = null): static
    {
        return $this->paid()->state(fn (array $attributes) => [
            'status' => OrderStatus::Assigned,
            'driver_id' => $driver ?? User::factory()->driver(),
            'scheduled_for' => today(config()->string('kotak.timezone'))->toDateString(),
        ]);
    }

    /**
     * Collected from the branch by the driver.
     */
    public function pickedUp(?User $driver = null): static
    {
        return $this->assigned($driver)->state(fn (array $attributes) => [
            'status' => OrderStatus::PickedUp,
        ]);
    }

    /**
     * Delivered, with a successful delivery attempt.
     */
    public function delivered(?User $driver = null): static
    {
        return $this->pickedUp($driver)
            ->state(fn (array $attributes) => [
                'status' => OrderStatus::Delivered,
                'delivered_at' => now(),
            ])
            ->afterCreating(function (Order $order): void {
                DeliveryAttempt::factory()->for($order)->create(['driver_id' => $order->driver_id]);
            });
    }

    /**
     * The last delivery attempt failed (one failed attempt so far).
     */
    public function deliveryFailed(?User $driver = null): static
    {
        return $this->pickedUp($driver)
            ->state(fn (array $attributes) => [
                'status' => OrderStatus::DeliveryFailed,
            ])
            ->afterCreating(function (Order $order): void {
                DeliveryAttempt::factory()->failed()->for($order)->create(['driver_id' => $order->driver_id]);
            });
    }

    /**
     * Returned to the sender after a failed delivery.
     */
    public function returnedToSender(): static
    {
        return $this->deliveryFailed()->state(fn (array $attributes) => [
            'status' => OrderStatus::ReturnedToSender,
        ]);
    }

    /**
     * Cancelled by the customer before drop-off.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * Get the sequence of statuses that leads to the given status.
     *
     * @return list<OrderStatus>
     */
    private static function historyTo(OrderStatus $status): array
    {
        $delivery = [
            OrderStatus::Created,
            OrderStatus::DroppedOff,
            OrderStatus::Paid,
            OrderStatus::Assigned,
            OrderStatus::PickedUp,
        ];

        return match ($status) {
            OrderStatus::Cancelled => [OrderStatus::Created, OrderStatus::Cancelled],
            OrderStatus::Delivered => [...$delivery, OrderStatus::Delivered],
            OrderStatus::DeliveryFailed => [...$delivery, OrderStatus::DeliveryFailed],
            OrderStatus::ReturnedToSender => [...$delivery, OrderStatus::DeliveryFailed, OrderStatus::ReturnedToSender],
            default => array_slice($delivery, 0, (int) array_search($status, $delivery, true) + 1),
        };
    }
}
