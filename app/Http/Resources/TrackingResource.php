<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\OrderStatusEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public tracking data for anyone holding the tracking number.
 *
 * Deliberately contains no names, phone numbers, emails, street address,
 * prices or history notes: only the destination city and postcode.
 *
 * @mixin Order
 */
class TrackingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing(['branch', 'statusEvents.branch']);

        return [
            'tracking_number' => $this->formatted_tracking_number,
            'status' => $this->status->toOption(),
            'description' => $this->status->description(),
            'is_final' => $this->status->isFinal(),
            'destination' => [
                'city' => $this->city,
                'postcode' => $this->postcode,
            ],
            'branch' => [
                'name' => $this->branch->name,
                'city' => $this->branch->city,
            ],
            'chargeable_weight_g' => $this->chargeable_weight_g,
            'scheduled_for' => $this->scheduled_for?->toDateString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'delivered_at' => $this->delivered_at?->toIso8601ZuluString(),
            'events' => $this->statusEvents->map(fn (OrderStatusEvent $event): array => [
                'status' => $event->to_status->toOption(),
                'description' => $event->to_status->description(),
                'city' => $event->branch?->city,
                'created_at' => $event->created_at->toIso8601ZuluString(),
            ])->values()->all(),
        ];
    }
}
