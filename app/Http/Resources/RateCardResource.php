<?php

namespace App\Http\Resources;

use App\Models\RateCard;
use App\Models\RateCardBand;
use App\Models\RateCardRoute;
use App\Models\RateCardZone;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A rate card version as admins see it, with where it stands (draft,
 * scheduled, current or past). The zones and the routes with their bands
 * are included when loaded.
 *
 * @mixin RateCard
 */
class RateCardResource extends JsonResource
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
            'phase' => $this->phase()->toOption(),
            'effective_from' => $this->effective_from?->toIso8601ZuluString(),
            'volumetric_divisor' => $this->volumetric_divisor,
            'notes' => $this->notes,
            'published_at' => $this->published_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
            'created_by' => $this->whenLoaded('createdBy', fn (User $user): array => ['id' => $user->id, 'name' => $user->name]),
            'published_by' => $this->whenLoaded('publishedBy', fn (User $user): array => ['id' => $user->id, 'name' => $user->name]),
            'zones' => $this->whenLoaded('zones', fn () => $this->zones->map(fn (RateCardZone $zone): array => [
                'id' => $zone->id,
                'code' => $zone->code,
                'name' => $zone->name,
                'states' => $zone->states,
            ])->values()),
            'routes' => $this->whenLoaded('routes', fn () => $this->routes->map(fn (RateCardRoute $route): array => [
                'id' => $route->id,
                'origin_zone_id' => $route->origin_zone_id,
                'destination_zone_id' => $route->destination_zone_id,
                'extra_kg_sen' => $route->extra_kg_sen,
                'bands' => $route->bands->map(fn (RateCardBand $band): array => [
                    'max_weight_g' => $band->max_weight_g,
                    'price_sen' => $band->price_sen,
                ])->values(),
            ])->values()),
        ];
    }
}
