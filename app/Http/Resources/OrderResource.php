<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;

/**
 * The full order, including contact details and address, for its customer,
 * staff and admins. Drivers get DriverJobResource instead.
 *
 * @mixin Order
 */
class OrderResource extends OrderSummaryResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'status_description' => $this->status->description(),
            'is_final' => $this->status->isFinal(),
            'sender_name' => $this->sender_name,
            'sender_phone' => $this->sender_phone,
            'receiver_phone' => $this->receiver_phone,
            'address_line2' => $this->address_line2,
            'declared_weight_g' => $this->declared_weight_g,
            'length_cm' => $this->length_cm,
            'width_cm' => $this->width_cm,
            'height_cm' => $this->height_cm,
            'measured_weight_g' => $this->measured_weight_g,
            'dropped_off_at' => $this->dropped_off_at?->toIso8601ZuluString(),
            'paid_at' => $this->paid_at?->toIso8601ZuluString(),
            'delivered_at' => $this->delivered_at?->toIso8601ZuluString(),
            'cancelled_at' => $this->cancelled_at?->toIso8601ZuluString(),
            'branch' => BranchResource::make($this->whenLoaded('branch')),
            'payment' => PaymentResource::make($this->whenLoaded('payment')),
            'delivery_attempts' => DeliveryAttemptResource::collection($this->whenLoaded('deliveryAttempts')),
            'status_events' => StatusEventResource::collection($this->whenLoaded('statusEvents')),
        ];
    }
}
