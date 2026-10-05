<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
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
            'receipt_number' => $this->receipt_number,
            'amount_sen' => $this->amount_sen,
            'method' => $this->method->toOption(),
            'reference' => $this->reference,
            'paid_at' => $this->paid_at->toIso8601ZuluString(),
            'received_by' => $this->whenLoaded('receivedBy', fn (User $staff): array => [
                'id' => $staff->id,
                'name' => $staff->name,
            ]),
            'branch' => BranchResource::make($this->whenLoaded('branch')),
            // For the receipt: only what it prints about the parcel, never
            // the sender's or receiver's contact details.
            'order' => $this->whenLoaded('order', fn (Order $order): array => [
                'id' => $order->id,
                'tracking_number' => $order->formatted_tracking_number,
                'item_name' => $order->item_name,
                'receiver_name' => $order->receiver_name,
                'city' => $order->city,
                'postcode' => $order->postcode,
                'measured_weight_g' => $order->measured_weight_g,
                'length_cm' => $order->length_cm,
                'width_cm' => $order->width_cm,
                'height_cm' => $order->height_cm,
                'chargeable_weight_g' => $order->chargeable_weight_g,
            ]),
        ];
    }
}
