<?php

namespace App\Support;

use App\Enums\MalaysianState;
use App\Models\RateCard;
use App\Models\RateCardBand;
use App\Models\RateCardRoute;
use App\Models\RateCardZone;
use Carbon\CarbonImmutable;

/**
 * A published rate card as prices are worked out from it: its zones, its
 * routes (by zone code) and each route's bands, lightest first. The array
 * form is what App\Support\RateCards caches and what the frontend's
 * lib/pricing.ts reads, so the browser prices a parcel the same way.
 *
 * @phpstan-type Zone array{code: string, name: string, states: list<string>}
 * @phpstan-type Band array{maxWeightG: int, priceSen: int}
 * @phpstan-type Route array{from: string, to: string, extraKgSen: int, bands: list<Band>}
 * @phpstan-type Card array{id: int, name: string, effectiveFrom: string|null, divisor: int, zones: list<Zone>, routes: list<Route>}
 */
final readonly class PriceList
{
    /**
     * Create a new price list.
     *
     * @param  list<Zone>  $zones
     * @param  list<Route>  $routes
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?CarbonImmutable $effectiveFrom,
        public int $divisor,
        public array $zones,
        public array $routes,
    ) {}

    /**
     * Build the price list from its array form.
     *
     * @param  Card  $card
     */
    public static function fromArray(array $card): self
    {
        return new self(
            $card['id'],
            $card['name'],
            $card['effectiveFrom'] === null ? null : CarbonImmutable::parse($card['effectiveFrom']),
            $card['divisor'],
            $card['zones'],
            $card['routes'],
        );
    }

    /**
     * Build the price list from a published card loaded with "zones" and "routes.bands".
     */
    public static function fromModel(RateCard $card): self
    {
        $codes = $card->zones->pluck('code', 'id')->all();

        return new self(
            $card->id,
            $card->name,
            $card->effective_from?->toImmutable(),
            $card->volumetric_divisor,
            array_values(array_map(fn (RateCardZone $zone): array => [
                'code' => $zone->code,
                'name' => $zone->name,
                'states' => $zone->states,
            ], $card->zones->all())),
            array_values(array_map(fn (RateCardRoute $route): array => [
                'from' => $codes[$route->origin_zone_id],
                'to' => $codes[$route->destination_zone_id],
                'extraKgSen' => (int) $route->extra_kg_sen,
                'bands' => array_values(array_map(fn (RateCardBand $band): array => [
                    'maxWeightG' => $band->max_weight_g,
                    'priceSen' => $band->price_sen,
                ], $route->bands->sortBy('max_weight_g')->all())),
            ], $card->routes->all())),
        );
    }

    /**
     * Get the zone the state belongs to.
     *
     * @return Zone|null
     */
    public function zoneFor(MalaysianState $state): ?array
    {
        foreach ($this->zones as $zone) {
            if (in_array($state->value, $zone['states'], true)) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * Get the route from one zone to another, by zone code.
     *
     * @return Route|null
     */
    public function route(string $from, string $to): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['from'] === $from && $route['to'] === $to) {
                return $route;
            }
        }

        return null;
    }

    /**
     * Get the array form, for the cache and the frontend.
     *
     * @return Card
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'effectiveFrom' => $this->effectiveFrom?->toIso8601ZuluString(),
            'divisor' => $this->divisor,
            'zones' => $this->zones,
            'routes' => $this->routes,
        ];
    }
}
