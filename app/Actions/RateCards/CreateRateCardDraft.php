<?php

namespace App\Actions\RateCards;

use App\Enums\RateCardStatus;
use App\Models\RateCard;
use App\Models\RateCardBand;
use App\Models\RateCardRoute;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateRateCardDraft
{
    /**
     * Start a new draft: a copy of the given card, with its zones, routes
     * and bands, or a blank one. Any version can be copied, so older prices
     * can be brought back.
     */
    public function handle(User $admin, ?RateCard $source = null): RateCard
    {
        if ($source === null) {
            return DB::transaction(fn (): RateCard => $this->newDraft($admin, 'New rates', RateCard::DEFAULT_VOLUMETRIC_DIVISOR, null));
        }

        $source->loadMissing(['zones', 'routes.bands']);
        $codes = $source->zones->pluck('code', 'id');

        $routes = array_map(fn (RateCardRoute $route): array => [
            'from' => (string) $codes[$route->origin_zone_id],
            'to' => (string) $codes[$route->destination_zone_id],
            'extraKgSen' => $route->extra_kg_sen,
            'bands' => array_map(fn (RateCardBand $band): array => [
                'maxWeightG' => $band->max_weight_g,
                'priceSen' => $band->price_sen,
            ], array_values($route->bands->all())),
        ], array_values($source->routes->all()));

        return $this->withRoutes($admin, $source, $routes, Str::limit("Copy of {$source->name}", 100, ''), $source->notes);
    }

    /**
     * Start a new draft with the zones and divisor of the given card and
     * other prices: routes naming their zones by code, weights in grams and
     * prices in sen, as an import reads them from a spreadsheet. All of it
     * is written in one transaction.
     *
     * @param  list<array{from: string, to: string, extraKgSen: int|null, bands: list<array{maxWeightG: int, priceSen: int}>}>  $routes
     *
     * @throws InvalidArgumentException when a route names a zone the card does not have
     */
    public function withRoutes(User $admin, RateCard $zonesFrom, array $routes, string $name, ?string $notes = null): RateCard
    {
        return DB::transaction(function () use ($admin, $zonesFrom, $routes, $name, $notes): RateCard {
            $zonesFrom->loadMissing('zones');
            $draft = $this->newDraft($admin, $name, $zonesFrom->volumetric_divisor, $notes);
            $zoneIds = [];

            foreach ($zonesFrom->zones as $zone) {
                $zoneIds[$zone->code] = $draft->zones()->create($zone->only(['code', 'name', 'states']))->id;
            }

            foreach ($routes as $route) {
                if (! isset($zoneIds[$route['from']], $zoneIds[$route['to']])) {
                    throw new InvalidArgumentException("No zone {$route['from']} or {$route['to']} on rate card {$zonesFrom->id}.");
                }

                $draft->routes()->create([
                    'origin_zone_id' => $zoneIds[$route['from']],
                    'destination_zone_id' => $zoneIds[$route['to']],
                    'extra_kg_sen' => $route['extraKgSen'],
                ])->bands()->createMany(array_map(fn (array $band): array => [
                    'max_weight_g' => $band['maxWeightG'],
                    'price_sen' => $band['priceSen'],
                ], $route['bands']));
            }

            return $draft;
        });
    }

    /**
     * Save an empty draft with the given details.
     */
    private function newDraft(User $admin, string $name, int $divisor, ?string $notes): RateCard
    {
        $draft = (new RateCard)->forceFill([
            'name' => $name,
            'status' => RateCardStatus::Draft,
            'volumetric_divisor' => $divisor,
            'notes' => $notes,
            'created_by' => $admin->id,
        ]);
        $draft->save();

        return $draft;
    }
}
