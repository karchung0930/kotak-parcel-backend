<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\User;
use App\Services\OrderStatusService;
use App\Support\PriceCalculator;
use App\Support\TrackingNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateOrder
{
    /**
     * Create a new action instance.
     */
    public function __construct(
        private PriceCalculator $pricing,
        private OrderStatusService $statuses,
    ) {}

    /**
     * Create a delivery order, estimating the price from the declared weight and size.
     *
     * @param  array<string, mixed>  $data  validated input: branch_id, receiver_name, receiver_phone,
     *                                      address_line1, address_line2, city, state, postcode, item_name,
     *                                      declared_weight_g, length_cm, width_cm, height_cm
     *
     * @throws ValidationException
     */
    public function handle(User $customer, array $data): Order
    {
        if ($customer->phone === null) {
            throw ValidationException::withMessages([
                'phone' => 'Add a mobile number to your profile before sending a parcel.',
            ]);
        }

        // Only fillable, customer-supplied fields are taken from the input.
        $order = new Order($data);

        $chargeable = $this->pricing->chargeableWeightGrams(
            $order->declared_weight_g, $order->length_cm, $order->width_cm, $order->height_cm,
        );

        $order->forceFill([
            'tracking_number' => TrackingNumber::unique(),
            'customer_id' => $customer->id,
            'sender_name' => $customer->name,
            'sender_phone' => $customer->phone,
            'chargeable_weight_g' => $chargeable,
            'estimated_price_sen' => $this->pricing->priceSen($chargeable),
        ]);

        return DB::transaction(function () use ($order, $customer): Order {
            $order->save();

            $this->statuses->recordCreation($order, $customer);

            return $order;
        });
    }
}
