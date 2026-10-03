<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderStatusService;
use App\Support\PriceCalculator;
use App\Support\RateCards;

class RecordDropOff
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
     * Record that branch staff received and weighed the parcel, and set its
     * final price with the rate card current now, on the route from the
     * branch where it was handed in.
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

        // The parcel is now physically at the staff member's branch.
        $branch = $staff->branch ?? $order->branch;

        $quote = $this->pricing->quote(
            $this->rateCards->current(),
            $branch->state,
            $order->state,
            $measuredWeightG, $lengthCm, $widthCm, $heightCm,
        );

        return $this->statuses->transition($order, OrderStatus::DroppedOff, $staff, attributes: [
            'branch_id' => $branch->id,
            'measured_weight_g' => $measuredWeightG,
            'length_cm' => $lengthCm,
            'width_cm' => $widthCm,
            'height_cm' => $heightCm,
            'chargeable_weight_g' => $quote->chargeableG,
            'final_price_sen' => $quote->priceSen,
            'final_rate_card_id' => $quote->rateCardId,
        ]);
    }
}
