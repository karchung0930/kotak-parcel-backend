<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A delivery as its driver sees it: where to collect it, what it is and who
 * receives it. Never the sender's details, prices or payment, nor the
 * receiver's email, which is only for their delivery updates.
 *
 * @mixin Order
 */
class DriverJobResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tracking_number' => $this->formatted_tracking_number,
            'status' => $this->status->toOption(),
            'scheduled_for' => $this->scheduled_for?->toDateString(),
            'is_overdue' => $this->isOverdue(),
            'receiver_name' => $this->receiver_name,
            'receiver_phone' => $this->receiver_phone,
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'city' => $this->city,
            'state' => $this->state->value,
            'postcode' => $this->postcode,
            'item_name' => $this->item_name,
            'measured_weight_g' => $this->measured_weight_g,
            'length_cm' => $this->length_cm,
            'width_cm' => $this->width_cm,
            'height_cm' => $this->height_cm,
            // The pick-up branch, with its address and opening hours.
            'branch' => BranchResource::make($this->whenLoaded('branch')),
            'delivery_attempts' => DeliveryAttemptResource::collection($this->whenLoaded('deliveryAttempts')),
            'failed_attempts' => $this->whenCounted('failedAttempts'),
        ];
    }
}
