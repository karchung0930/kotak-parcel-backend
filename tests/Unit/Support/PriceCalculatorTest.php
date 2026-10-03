<?php

namespace Tests\Unit\Support;

use App\Enums\MalaysianState;
use App\Support\PriceCalculator;
use App\Support\PriceList;
use App\Support\PriceQuote;
use Illuminate\Support\Arr;
use LogicException;
use Tests\TestCase;

class PriceCalculatorTest extends TestCase
{
    private PriceCalculator $pricing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pricing = new PriceCalculator;
    }

    public function test_the_brief_example_is_charged_by_volumetric_weight()
    {
        // 4.2 kg in a 40 x 30 x 25 cm box: volumetric 6.0 kg wins.
        $quote = $this->quote(self::flat(), 4200, 40, 30, 25);

        $this->assertSame(6000, $quote->volumetricG);
        $this->assertSame(6000, $quote->chargeableG);
        $this->assertSame(1800, $quote->priceSen);
    }

    public function test_heavy_small_parcels_are_charged_by_actual_weight()
    {
        $this->assertSame(9500, $this->quote(self::flat(), 9500, 20, 20, 10)->chargeableG);
    }

    public function test_volumetric_weight_is_rounded_up_to_the_next_gram()
    {
        // 33 x 27 x 19 = 16929 cm3 / 5000 = 3.3858 kg
        $this->assertSame(3386, $this->pricing->volumetricWeightGrams(5000, 33, 27, 19));
        // The same box with a divisor of 6000: 2.8215 kg.
        $this->assertSame(2822, $this->pricing->volumetricWeightGrams(6000, 33, 27, 19));
    }

    public function test_one_band_up_to_1_kg_and_a_price_per_extra_kg_is_the_old_flat_formula()
    {
        foreach ([1, 999, 1000, 1001, 1999, 2000, 2001, 5100, 12345, 29999, 30000] as $grams) {
            $oldPrice = 800 + max(0, intdiv($grams + 999, 1000) - 1) * 200;

            $this->assertSame($oldPrice, $this->quote(self::flat(), $grams, 1, 1, 1)->priceSen, "{$grams} g");
        }
    }

    public function test_the_lightest_band_that_covers_the_weight_prices_the_parcel()
    {
        $card = self::banded();

        // Up to 0.5 kg, exactly 0.5 kg, then into the 1 kg and 2 kg bands.
        $this->assertSame([500, 900, 0], $this->band($this->quote($card, 1, 1, 1, 1, MalaysianState::Sabah)));
        $this->assertSame([500, 900, 0], $this->band($this->quote($card, 500, 1, 1, 1, MalaysianState::Sabah)));
        $this->assertSame([1000, 1200, 0], $this->band($this->quote($card, 501, 1, 1, 1, MalaysianState::Sabah)));
        $this->assertSame([2000, 1700, 0], $this->band($this->quote($card, 1001, 1, 1, 1, MalaysianState::Sabah)));
        $this->assertSame([5000, 3100, 0], $this->band($this->quote($card, 5000, 1, 1, 1, MalaysianState::Sabah)));
    }

    public function test_above_the_highest_band_each_started_kg_costs_the_extra_rate()
    {
        $card = self::banded();

        $justOver = $this->quote($card, 5001, 1, 1, 1, MalaysianState::Sabah);
        $this->assertSame([5000, 3100, 1], $this->band($justOver));
        $this->assertSame(3100 + 500, $justOver->priceSen);

        $heavier = $this->quote($card, 7200, 1, 1, 1, MalaysianState::Sabah);
        $this->assertSame(3, $heavier->extraKg);
        $this->assertSame(3100 + 3 * 500, $heavier->priceSen);
    }

    public function test_the_card_s_divisor_sets_the_volumetric_weight()
    {
        // 60 x 40 x 30 cm at 800 g: 12 kg by size with a divisor of 6000.
        $quote = $this->quote(self::banded(), 800, 60, 40, 30, MalaysianState::Sabah);

        $this->assertSame(12000, $quote->volumetricG);
        $this->assertSame(12000, $quote->chargeableG);
        $this->assertSame(3100 + 7 * 500, $quote->priceSen);
    }

    public function test_routes_run_from_the_branch_s_zone_to_the_address_s_zone()
    {
        $card = self::banded();

        $westToEast = $this->pricing->quote($card, MalaysianState::Selangor, MalaysianState::Sarawak, 1600, 1, 1, 1);
        $eastToWest = $this->pricing->quote($card, MalaysianState::Sarawak, MalaysianState::Selangor, 1600, 1, 1, 1);
        $withinWest = $this->pricing->quote($card, MalaysianState::Selangor, MalaysianState::Johor, 1600, 1, 1, 1);

        $this->assertSame(['Peninsular Malaysia', 'East Malaysia', 1700], [$westToEast->originZone, $westToEast->destinationZone, $westToEast->priceSen]);
        $this->assertSame(['East Malaysia', 'Peninsular Malaysia', 1800], [$eastToWest->originZone, $eastToWest->destinationZone, $eastToWest->priceSen]);
        $this->assertSame(['Peninsular Malaysia', 'Peninsular Malaysia', 1000], [$withinWest->originZone, $withinWest->destinationZone, $withinWest->priceSen]);
        $this->assertSame(2, $westToEast->rateCardId);
    }

    public function test_a_card_without_a_route_between_the_zones_cannot_price_the_parcel()
    {
        $card = PriceList::fromArray([...self::banded()->toArray(), 'routes' => []]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Rate card 2 has no route from Peninsular Malaysia to East Malaysia.');

        $this->pricing->quote($card, MalaysianState::Selangor, MalaysianState::Sabah, 1000, 1, 1, 1);
    }

    public function test_the_shared_cases_give_the_quotes_the_frontend_gives()
    {
        // tests/js/fixtures/pricing-cases.json in the frontend repository: its
        // pricing check runs the same cases through lib/pricing.ts.
        $path = dirname(config()->string('inertia.pages.paths.0'), 3).'/tests/js/fixtures/pricing-cases.json';
        $this->assertFileExists($path);

        $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($fixture['cases']);

        foreach ($fixture['cases'] as $case) {
            $quote = $this->pricing->quote(
                PriceList::fromArray($fixture['cards'][$case['card']]),
                MalaysianState::from($case['origin']),
                MalaysianState::from($case['destination']),
                $case['weightG'],
                $case['lengthCm'],
                $case['widthCm'],
                $case['heightCm'],
            );

            $this->assertSame($case['quote'], $quote->toArray(), $case['name']);
        }
    }

    public function test_pricing_rules_are_shared_with_the_frontend()
    {
        $rules = $this->pricing->rules(self::flat());

        $this->assertSame([
            ...self::flat()->toArray(),
            'maxWeightG' => 30000,
            'maxDimensionCm' => 150,
        ], $rules);
        $this->assertSame(['maxWeightG' => 1000, 'priceSen' => 800], $rules['routes'][0]['bands'][0]);
    }

    public function test_public_pricing_rules_leave_out_the_card_s_id_and_name()
    {
        $rules = $this->pricing->publicRules(self::flat());

        $this->assertSame(['effectiveFrom', 'divisor', 'zones', 'routes', 'maxWeightG', 'maxDimensionCm'], array_keys($rules));
        $this->assertSame(Arr::except($this->pricing->rules(self::flat()), ['id', 'name']), $rules);
    }

    /**
     * Price a parcel from Selangor to the given state.
     */
    private function quote(PriceList $card, int $grams, int $length, int $width, int $height, MalaysianState $to = MalaysianState::KualaLumpur): PriceQuote
    {
        return $this->pricing->quote($card, MalaysianState::Selangor, $to, $grams, $length, $width, $height);
    }

    /**
     * The band that priced the quote and the extra kg on top of it.
     *
     * @return array{int, int, int}
     */
    private function band(PriceQuote $quote): array
    {
        return [$quote->bandMaxG, $quote->bandPriceSen, $quote->extraKg];
    }

    /**
     * One zone for the whole country, with the old flat rates.
     */
    private static function flat(): PriceList
    {
        return PriceList::fromArray([
            'id' => 1,
            'name' => 'Standard rates',
            'effectiveFrom' => null,
            'divisor' => 5000,
            'zones' => [['code' => 'malaysia', 'name' => 'Malaysia', 'states' => array_column(MalaysianState::cases(), 'value')]],
            'routes' => [['from' => 'malaysia', 'to' => 'malaysia', 'extraKgSen' => 200, 'bands' => [['maxWeightG' => 1000, 'priceSen' => 800]]]],
        ]);
    }

    /**
     * Peninsular and East Malaysia, with weight bands to East Malaysia and a divisor of 6000.
     */
    private static function banded(): PriceList
    {
        $east = ['Sabah', 'Sarawak', 'Labuan'];
        $bands = fn (array $prices): array => array_map(
            fn (int $weight, int $price): array => ['maxWeightG' => $weight, 'priceSen' => $price],
            array_keys($prices),
            $prices,
        );

        return PriceList::fromArray([
            'id' => 2,
            'name' => 'Zone rates',
            'effectiveFrom' => '2026-10-01T00:00:00Z',
            'divisor' => 6000,
            'zones' => [
                ['code' => 'west', 'name' => 'Peninsular Malaysia', 'states' => array_values(array_diff(array_column(MalaysianState::cases(), 'value'), $east))],
                ['code' => 'east', 'name' => 'East Malaysia', 'states' => $east],
            ],
            'routes' => [
                ['from' => 'west', 'to' => 'west', 'extraKgSen' => 200, 'bands' => $bands([1000 => 800])],
                ['from' => 'west', 'to' => 'east', 'extraKgSen' => 500, 'bands' => $bands([500 => 900, 1000 => 1200, 2000 => 1700, 5000 => 3100])],
                ['from' => 'east', 'to' => 'west', 'extraKgSen' => 550, 'bands' => $bands([500 => 1000, 1000 => 1300, 2000 => 1800, 5000 => 3300])],
                ['from' => 'east', 'to' => 'east', 'extraKgSen' => 250, 'bands' => $bands([1000 => 900, 3000 => 1300])],
            ],
        ]);
    }
}
