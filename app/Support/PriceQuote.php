<?php

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;

/**
 * The price of one parcel on one route of a rate card, with how it was
 * worked out. Made by PriceCalculator::quote(); lib/pricing.ts in the
 * frontend gives the same fields for the live estimate.
 *
 * @implements Arrayable<string, int|string>
 */
final readonly class PriceQuote implements Arrayable
{
    /**
     * Create a new quote.
     *
     * @param  int  $bandMaxG  the band that priced the parcel (the highest band when it is heavier)
     * @param  int  $extraKg  started kg above the highest band, each charged at $extraKgSen
     */
    public function __construct(
        public int $rateCardId,
        public string $originZone,
        public string $destinationZone,
        public int $actualG,
        public int $volumetricG,
        public int $chargeableG,
        public int $bandMaxG,
        public int $bandPriceSen,
        public int $extraKg,
        public int $extraKgSen,
        public int $priceSen,
    ) {}

    /**
     * Get the quote as an array, with the frontend's field names.
     *
     * @return array{
     *     rateCardId: int,
     *     originZone: string,
     *     destinationZone: string,
     *     actualG: int,
     *     volumetricG: int,
     *     chargeableG: int,
     *     bandMaxG: int,
     *     bandPriceSen: int,
     *     extraKg: int,
     *     extraKgSen: int,
     *     priceSen: int,
     * }
     */
    public function toArray(): array
    {
        return [
            'rateCardId' => $this->rateCardId,
            'originZone' => $this->originZone,
            'destinationZone' => $this->destinationZone,
            'actualG' => $this->actualG,
            'volumetricG' => $this->volumetricG,
            'chargeableG' => $this->chargeableG,
            'bandMaxG' => $this->bandMaxG,
            'bandPriceSen' => $this->bandPriceSen,
            'extraKg' => $this->extraKg,
            'extraKgSen' => $this->extraKgSen,
            'priceSen' => $this->priceSen,
        ];
    }
}
