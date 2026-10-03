<?php

namespace App\Actions\RateCards;

use App\Enums\RateCardStatus;
use App\Models\RateCard;
use App\Models\RateCardBand;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateRateCardDraft
{
    /**
     * Start a new draft: a copy of the given card, with its zones, routes
     * and bands, or a blank one. Any version can be copied, so older prices
     * can be brought back.
     */
    public function handle(User $admin, ?RateCard $source = null): RateCard
    {
        return DB::transaction(function () use ($admin, $source): RateCard {
            $draft = (new RateCard)->forceFill([
                'name' => $source === null ? 'New rates' : Str::limit("Copy of {$source->name}", 100, ''),
                'status' => RateCardStatus::Draft,
                'volumetric_divisor' => $source->volumetric_divisor ?? RateCard::DEFAULT_VOLUMETRIC_DIVISOR,
                'notes' => $source?->notes,
                'created_by' => $admin->id,
            ]);
            $draft->save();

            if ($source === null) {
                return $draft;
            }

            $source->loadMissing(['zones', 'routes.bands']);
            $zoneIds = [];

            foreach ($source->zones as $zone) {
                $zoneIds[$zone->id] = $draft->zones()->create($zone->only(['code', 'name', 'states']))->id;
            }

            foreach ($source->routes as $route) {
                $draft->routes()->create([
                    'origin_zone_id' => $zoneIds[$route->origin_zone_id],
                    'destination_zone_id' => $zoneIds[$route->destination_zone_id],
                    'extra_kg_sen' => $route->extra_kg_sen,
                ])->bands()->createMany(
                    $route->bands->map(fn (RateCardBand $band): array => $band->only(['max_weight_g', 'price_sen']))->all(),
                );
            }

            return $draft;
        });
    }
}
