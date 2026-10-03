<?php

namespace App\Support;

use App\Enums\MalaysianState;
use Illuminate\Support\Arr;
use LogicException;

/**
 * Prices parcels from a rate card, in integer grams and sen only.
 *
 * The parcel is charged on the greater of its actual and volumetric weight
 * (length x width x height in cm / the card's divisor). The route runs from
 * the zone of the drop-off branch's state to the zone of the delivery
 * address's state. The price is that of the route's lightest band that
 * covers the weight; above the highest band, each started kg costs the
 * route's price per extra kg on top of the highest band's price.
 *
 * Example: 4.2 kg in a 40 x 30 x 25 cm box has a volumetric weight of 6 kg.
 * With one band up to 1 kg at RM 8.00 and RM 2.00 per extra kg, it costs
 * RM 8.00 + 5 x RM 2.00 = RM 18.00.
 *
 * @phpstan-import-type Band from PriceList
 */
class PriceCalculator
{
    /**
     * Price a parcel on the route between two states.
     *
     * @throws LogicException when the card has no zone or route for the states, which a published card always has
     */
    public function quote(
        PriceList $card,
        MalaysianState $origin,
        MalaysianState $destination,
        int $actualGrams,
        int $lengthCm,
        int $widthCm,
        int $heightCm,
    ): PriceQuote {
        $from = $card->zoneFor($origin) ?? throw new LogicException("Rate card {$card->id} has no zone for {$origin->value}.");
        $to = $card->zoneFor($destination) ?? throw new LogicException("Rate card {$card->id} has no zone for {$destination->value}.");
        $route = $card->route($from['code'], $to['code'])
            ?? throw new LogicException("Rate card {$card->id} has no route from {$from['name']} to {$to['name']}.");

        $volumetric = $this->volumetricWeightGrams($card->divisor, $lengthCm, $widthCm, $heightCm);
        $chargeable = max($actualGrams, $volumetric);
        [$band, $extraKg] = $this->band($route['bands'], $chargeable);

        return new PriceQuote(
            rateCardId: $card->id,
            originZone: $from['name'],
            destinationZone: $to['name'],
            actualG: $actualGrams,
            volumetricG: $volumetric,
            chargeableG: $chargeable,
            bandMaxG: $band['maxWeightG'],
            bandPriceSen: $band['priceSen'],
            extraKg: $extraKg,
            extraKgSen: $route['extraKgSen'],
            priceSen: $band['priceSen'] + $extraKg * $route['extraKgSen'],
        );
    }

    /**
     * Get the volumetric weight in grams, rounded up to the next gram.
     */
    public function volumetricWeightGrams(int $divisor, int $lengthCm, int $widthCm, int $heightCm): int
    {
        // Integer ceiling of (volume / divisor) kg expressed in grams.
        return intdiv($lengthCm * $widthCm * $heightCm * 1000 + $divisor - 1, $divisor);
    }

    /**
     * Get the pricing rules shared with the frontend so it can show live
     * estimates: the card in its array form, with the parcel limits.
     *
     * @return array<string, mixed>
     */
    public function rules(PriceList $card): array
    {
        return [
            ...$card->toArray(),
            'maxWeightG' => config()->integer('kotak.max_weight_g'),
            'maxDimensionCm' => config()->integer('kotak.max_dimension_cm'),
        ];
    }

    /**
     * Get the pricing rules for customers and visitors: the same, without
     * the card's id and name, which admins choose for themselves.
     *
     * @return array<string, mixed>
     */
    public function publicRules(PriceList $card): array
    {
        return Arr::except($this->rules($card), ['id', 'name']);
    }

    /**
     * Find the band that prices the weight, with the started kg above it
     * when the weight is over the highest band.
     *
     * @param  list<Band>  $bands  lightest first
     * @return array{Band, int}
     */
    private function band(array $bands, int $chargeableGrams): array
    {
        foreach ($bands as $band) {
            if ($band['maxWeightG'] >= $chargeableGrams) {
                return [$band, 0];
            }
        }

        $highest = $bands[array_key_last($bands) ?? throw new LogicException('A route has no weight bands.')];

        return [$highest, intdiv($chargeableGrams - $highest['maxWeightG'] + 999, 1000)];
    }
}
