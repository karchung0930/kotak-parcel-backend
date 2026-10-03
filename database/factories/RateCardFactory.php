<?php

namespace Database\Factories;

use App\Enums\MalaysianState;
use App\Enums\RateCardStatus;
use App\Models\RateCard;
use App\Support\PriceList;
use App\Support\RateCards;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Rate cards for tests. Zones and routes are given in the array form of
 * App\Support\PriceList (routes name their zones by code). Creating a card
 * refreshes the cached published cards, as publishing does.
 *
 * @extends Factory<RateCard>
 *
 * @phpstan-import-type Zone from PriceList
 * @phpstan-import-type Route from PriceList
 */
class RateCardFactory extends Factory
{
    /**
     * Define the model's default state (an empty draft).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Standard rates', 'Festive season rates', 'New rates']),
            'status' => RateCardStatus::Draft,
            'volumetric_divisor' => RateCard::DEFAULT_VOLUMETRIC_DIVISOR,
        ];
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(fn () => app(RateCards::class)->refresh());
    }

    /**
     * Published, in effect from the given moment (now by default).
     */
    public function published(?CarbonInterface $from = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RateCardStatus::Published,
            'effective_from' => ($from ?? now())->toImmutable()->utc(),
            'published_at' => now(),
        ]);
    }

    /**
     * Published, taking effect at a future moment (a week from now by default).
     */
    public function scheduled(?CarbonInterface $from = null): static
    {
        return $this->published($from ?? now()->addWeek());
    }

    /**
     * With the given zones and routes, each route with its bands.
     *
     * @param  list<Zone>  $zones
     * @param  list<array{from: string, to: string, extraKgSen: int|null, bands: list<array{maxWeightG: int, priceSen: int}>}>  $routes
     */
    public function withRates(array $zones, array $routes): static
    {
        return $this->afterCreating(function (RateCard $card) use ($zones, $routes): void {
            $ids = [];

            foreach ($zones as $zone) {
                $ids[$zone['code']] = $card->zones()->create($zone)->id;
            }

            foreach ($routes as $route) {
                $card->routes()->create([
                    'origin_zone_id' => $ids[$route['from']],
                    'destination_zone_id' => $ids[$route['to']],
                    'extra_kg_sen' => $route['extraKgSen'],
                ])->bands()->createMany(array_map(fn (array $band): array => [
                    'max_weight_g' => $band['maxWeightG'],
                    'price_sen' => $band['priceSen'],
                ], $route['bands']));
            }

            app(RateCards::class)->refresh();
        });
    }

    /**
     * One zone with every state and one band up to 1 kg plus a price per
     * extra kg: the old flat formula (RM 8.00 and RM 2.00 by default).
     */
    public function flat(int $firstKgSen = 800, int $perKgSen = 200): static
    {
        return $this->withRates(
            [['code' => 'malaysia', 'name' => 'Malaysia', 'states' => array_column(MalaysianState::cases(), 'value')]],
            [['from' => 'malaysia', 'to' => 'malaysia', 'extraKgSen' => $perKgSen, 'bands' => [['maxWeightG' => 1000, 'priceSen' => $firstKgSen]]]],
        );
    }
}
