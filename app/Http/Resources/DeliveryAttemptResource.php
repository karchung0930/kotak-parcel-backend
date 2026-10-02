<?php

namespace App\Http\Resources;

use App\Models\DeliveryAttempt;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeliveryAttempt
 */
class DeliveryAttemptResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'outcome' => $this->outcome->toOption(),
            'recipient_name' => $this->recipient_name,
            'failure_reason' => $this->failure_reason?->toOption(),
            // The driver's free-text note is internal: customers only see the reason.
            'note' => $this->when($user !== null && ($user->isStaff() || $user->isDriver()), $this->note),
            'attempted_at' => $this->attempted_at->toIso8601ZuluString(),
            'has_photo' => $this->photo_path !== null,
            // The photo is private: this URL is authorised by OrderPolicy::viewProof.
            'photo_url' => $this->photo_path !== null ? route('orders.proof', $this->order_id) : null,
            'driver' => $this->whenLoaded('driver', fn (User $driver): array => [
                'id' => $driver->id,
                'name' => $driver->name,
            ]),
        ];
    }
}
