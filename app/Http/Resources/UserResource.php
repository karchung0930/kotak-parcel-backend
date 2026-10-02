<?php

namespace App\Http\Resources;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user account as admins see it (also used for driver lists).
 *
 * @mixin User
 */
class UserResource extends JsonResource
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
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role->toOption(),
            'branch' => $this->whenLoaded('branch', fn (Branch $branch): array => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
            ]),
            'vehicle_plate' => $this->vehicle_plate,
            'is_active' => $this->is_active,
            'email_verified_at' => $this->email_verified_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            // Present when loaded with User::withJobsCountOn($date).
            'jobs_count' => $this->whenCounted('jobs'),
        ];
    }
}
