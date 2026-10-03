<?php

namespace App\Actions\Orders;

use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderStatusService;
use App\Support\PriceCalculator;
use App\Support\RateCards;
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
        private RateCards $rateCards,
        private OrderStatusService $statuses,
    ) {}

    /**
     * Create a delivery order, estimating the price with the current rate
     * card from the declared weight and size, on the route from the chosen
     * branch to the delivery address. The drop-off deadline is fixed now,
     * from the current limit.
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

        $quote = $this->pricing->quote(
            $this->rateCards->current(),
            Branch::query()->findOrFail($order->branch_id)->state,
            $order->state,
            $order->declared_weight_g, $order->length_cm, $order->width_cm, $order->height_cm,
        );

        $order->forceFill([
            'tracking_number' => TrackingNumber::unique(),
            'customer_id' => $customer->id,
            'sender_name' => $customer->name,
            'sender_phone' => $customer->phone,
            'chargeable_weight_g' => $quote->chargeableG,
            'estimated_price_sen' => $quote->priceSen,
            'estimated_rate_card_id' => $quote->rateCardId,
            'drop_off_deadline' => Order::dropOffDeadlineFor(now())->toDateString(),
        ]);

        return DB::transaction(function () use ($order, $customer): Order {
            $order->save();

            $this->statuses->recordCreation($order, $customer);

            return $order;
        });
    }
}
