<?php

namespace App\Actions\RateCards;

use App\Models\RateCard;
use App\Models\RateCardZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateRateCardDraft
{
    /**
     * Save a draft as the admin left it: its details, then its zones and
     * routes, which replace the old ones. A draft may be incomplete, as
     * publishing checks it in full (PublishRateCard::problems()).
     *
     * The card is locked first, so it cannot be published halfway through.
     *
     * @param  array{
     *     name: string,
     *     volumetric_divisor: int,
     *     notes?: string|null,
     *     zones: list<array{name: string, states: list<string>}>,
     *     routes: list<array{origin: int, destination: int, extra_kg_sen?: int|null, bands: list<array{max_weight_g: int, price_sen: int}>}>,
     * }  $data  validated by UpdateRateCardRequest; routes name their zones by position
     *
     * @throws ValidationException when the card is no longer a draft, or has priced an order
     */
    public function handle(RateCard $card, array $data): RateCard
    {
        return DB::transaction(function () use ($card, $data): RateCard {
            $card = $card->freshLocked();

            if (! $card->isDraft()) {
                throw ValidationException::withMessages([
                    'card' => 'Published rates cannot be changed. Copy them into a new draft instead.',
                ]);
            }

            // Orders keep the prices they were charged with.
            if ($card->hasPricedOrders()) {
                throw ValidationException::withMessages([
                    'card' => 'These rates priced an order, so they are kept. Copy them into a new draft instead.',
                ]);
            }

            $card->forceFill([
                'name' => $data['name'],
                'volumetric_divisor' => $data['volumetric_divisor'],
                'notes' => $data['notes'] ?? null,
            ]);
            // Saved even when only the zones or prices changed, so the list shows the edit.
            $card->touch();

            // The bands go with their routes (cascading foreign keys).
            $card->routes()->delete();
            $card->zones()->delete();

            $zoneIds = [];

            foreach ($data['zones'] as $index => $zone) {
                $zoneIds[$index] = $card->zones()->create([
                    'code' => RateCardZone::codeFor($zone['name']),
                    'name' => $zone['name'],
                    'states' => $zone['states'],
                ])->id;
            }

            foreach ($data['routes'] as $route) {
                $card->routes()->create([
                    'origin_zone_id' => $zoneIds[$route['origin']],
                    'destination_zone_id' => $zoneIds[$route['destination']],
                    'extra_kg_sen' => $route['extra_kg_sen'] ?? null,
                ])->bands()->createMany($route['bands']);
            }

            return $card;
        });
    }
}
