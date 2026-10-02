<?php

namespace App\Http\Resources;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Branch
 */
class BranchResource extends JsonResource
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
            'code' => $this->code,
            'name' => $this->name,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state->value,
            'postcode' => $this->postcode,
            'phone' => $this->phone,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'opening_hours' => $this->opening_hours,
            'is_active' => $this->is_active,
        ];
    }
}
