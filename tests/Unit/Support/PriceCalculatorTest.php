<?php

namespace Tests\Unit\Support;

use App\Support\PriceCalculator;
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
        $chargeable = $this->pricing->chargeableWeightGrams(4200, 40, 30, 25);

        $this->assertSame(6000, $chargeable);
        $this->assertSame(1800, $this->pricing->priceSen($chargeable));
    }

    public function test_heavy_small_parcels_are_charged_by_actual_weight()
    {
        $this->assertSame(9500, $this->pricing->chargeableWeightGrams(9500, 20, 20, 10));
    }

    public function test_volumetric_weight_is_rounded_up_to_the_next_gram()
    {
        // 33 x 27 x 19 = 16929 cm3 / 5000 = 3.3858 kg
        $this->assertSame(3386, $this->pricing->volumetricWeightGrams(33, 27, 19));
    }

    public function test_price_is_base_for_the_first_kg_plus_a_rate_per_started_kg()
    {
        $this->assertSame(800, $this->pricing->priceSen(1));
        $this->assertSame(800, $this->pricing->priceSen(1000));
        $this->assertSame(1000, $this->pricing->priceSen(1001));
        $this->assertSame(1000, $this->pricing->priceSen(2000));
        $this->assertSame(6600, $this->pricing->priceSen(30000));
    }

    public function test_pricing_follows_the_configuration()
    {
        config(['kotak.base_price_sen' => 1000, 'kotak.per_kg_sen' => 300, 'kotak.volumetric_divisor' => 6000]);

        $this->assertSame(5000, $this->pricing->chargeableWeightGrams(1000, 40, 30, 25));
        $this->assertSame(2200, $this->pricing->priceSen(5000));
    }

    public function test_pricing_rules_are_shared_with_the_frontend()
    {
        $this->assertSame([
            'base' => 800,
            'perKg' => 200,
            'divisor' => 5000,
            'maxWeightG' => 30000,
            'maxDimensionCm' => 150,
        ], $this->pricing->toArray());
    }
}
