<?php

namespace App\Http\Resources;

use App\Models\Branch;
use App\Models\OrderStatusEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One step of an order's history, for its owner, staff and admins.
 *
 * @mixin OrderStatusEvent
 */
class StatusEventResource extends JsonResource
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
            'from' => $this->from_status?->toOption(),
            'status' => $this->to_status->toOption(),
            'description' => $this->to_status->description(),
            'note' => $this->note,
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'branch' => $this->whenLoaded('branch', fn (Branch $branch): array => [
                'id' => $branch->id,
                'name' => $branch->name,
                'city' => $branch->city,
            ]),
            // Only load "actor" for staff and admin views.
            'actor' => $this->whenLoaded('actor', fn (User $actor): array => [
                'id' => $actor->id,
                'name' => $actor->name,
                'role' => $actor->role->toOption(),
            ]),
        ];
    }
}
