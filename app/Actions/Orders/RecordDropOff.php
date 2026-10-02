<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderStatusService;
use App\Support\PriceCalculator;

class RecordDropOff
{
    /**
     * Create a new action instance.
     */
    public function __construct(
        private PriceCalculator $pricing,
        private OrderStatusService $statuses,
    ) {}

    /**
     * Record that branch staff received and weighed the parcel, and set its final price.
     *
     * Staff may correct the dimensions; the chargeable weight and price are recomputed.
     */
    public function handle(
        Order $order,
        User $staff,
        int $measuredWeightG,
        ?int $lengthCm = null,
        ?int $widthCm = null,
        ?int $heightCm = null,
    ): Order {
        $lengthCm ??= $order->length_cm;
        $widthCm ??= $order->width_cm;
        $heightCm ??= $order->height_cm;

        $chargeable = $this->pricing->chargeableWeightGrams($measuredWeightG, $lengthCm, $widthCm, $heightCm);

        return $this->statuses->transition($order, OrderStatus::DroppedOff, $staff, attributes: [
            // The parcel is now physically at the staff member's branch.
            'branch_id' => $staff->branch_id ?? $order->branch_id,
            'measured_weight_g' => $measuredWeightG,
            'length_cm' => $lengthCm,
            'width_cm' => $widthCm,
            'height_cm' => $heightCm,
            'chargeable_weight_g' => $chargeable,
            'final_price_sen' => $this->pricing->priceSen($chargeable),
        ]);
    }
}
