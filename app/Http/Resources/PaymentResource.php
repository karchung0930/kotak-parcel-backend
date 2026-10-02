<?php

namespace App\Http\Resources;

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
            'order' => OrderResource::make($this->whenLoaded('order')),
        ];
    }
}
