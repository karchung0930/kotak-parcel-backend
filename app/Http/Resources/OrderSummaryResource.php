<?php

namespace App\Http\Resources;

use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order row for customer, staff and admin lists (drivers get DriverJobResource).
 *
 * @mixin Order
 */
class OrderSummaryResource extends JsonResource
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
            'receiver_name' => $this->receiver_name,
            'address_line1' => $this->address_line1,
            'city' => $this->city,
            'state' => $this->state->value,
            'postcode' => $this->postcode,
            'item_name' => $this->item_name,
            'chargeable_weight_g' => $this->chargeable_weight_g,
            'estimated_price_sen' => $this->estimated_price_sen,
            'final_price_sen' => $this->final_price_sen,
            'scheduled_for' => $this->scheduled_for?->toDateString(),
            // The last day to drop the parcel off (Malaysia), while it is waiting for drop-off.
            'drop_off_deadline' => $this->dropOffDeadline()?->toDateString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
            'branch' => $this->whenLoaded('branch', fn (Branch $branch): array => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
                'city' => $branch->city,
            ]),
            'driver' => $this->whenLoaded('driver', fn (User $driver): array => [
                'id' => $driver->id,
                'name' => $driver->name,
                'vehicle_plate' => $driver->vehicle_plate,
            ]),
            // Only load "customer" for staff and admin views.
            'customer' => $this->whenLoaded('customer', fn (User $customer): array => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
            ]),
            'failed_attempts' => $this->whenCounted('failedAttempts'),
            'latest_attempt' => DeliveryAttemptResource::make($this->whenLoaded('latestAttempt')),
        ];
    }
}
