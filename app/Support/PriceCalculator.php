<?php

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Prices parcels from config/kotak.php using integer grams and sen only.
 *
 * Example: 4.2 kg in a 40 x 30 x 25 cm box has a volumetric weight of 6 kg,
 * so it is charged as 6 kg: RM 8.00 for the first kg + 5 x RM 2.00 = RM 18.00.
 *
 * @implements Arrayable<string, int>
 */
class PriceCalculator implements Arrayable
{
    /**
     * Get the weight the parcel is charged for: the greater of actual and volumetric weight.
     */
    public function chargeableWeightGrams(int $actualGrams, int $lengthCm, int $widthCm, int $heightCm): int
    {
        return max($actualGrams, $this->volumetricWeightGrams($lengthCm, $widthCm, $heightCm));
    }

    /**
     * Get the volumetric weight in grams, rounded up to the next gram.
     */
    public function volumetricWeightGrams(int $lengthCm, int $widthCm, int $heightCm): int
    {
        $divisor = config()->integer('kotak.volumetric_divisor');

        // Integer ceiling of (volume / divisor) kg expressed in grams.
        return intdiv($lengthCm * $widthCm * $heightCm * 1000 + $divisor - 1, $divisor);
    }

    /**
     * Get the price in sen: a base price for the first kg plus a rate for each additional started kg.
     */
    public function priceSen(int $chargeableGrams): int
    {
        $startedKg = intdiv($chargeableGrams + 999, 1000);

        return config()->integer('kotak.base_price_sen')
            + max(0, $startedKg - 1) * config()->integer('kotak.per_kg_sen');
    }

    /**
     * Get the pricing rules shared with the frontend so it can show live estimates.
     *
     * @return array{base: int, perKg: int, divisor: int, maxWeightG: int, maxDimensionCm: int}
     */
    public function toArray(): array
    {
        return [
            'base' => config()->integer('kotak.base_price_sen'),
            'perKg' => config()->integer('kotak.per_kg_sen'),
            'divisor' => config()->integer('kotak.volumetric_divisor'),
            'maxWeightG' => config()->integer('kotak.max_weight_g'),
            'maxDimensionCm' => config()->integer('kotak.max_dimension_cm'),
        ];
    }
}
