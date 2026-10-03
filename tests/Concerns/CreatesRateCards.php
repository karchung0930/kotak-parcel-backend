<?php

namespace Tests\Concerns;

use App\Enums\MalaysianState;
use App\Models\RateCard;
use Carbon\CarbonInterface;

/**
 * A published card with three zones, for tests that price between
 * Peninsular and East Malaysia. Within Peninsular Malaysia it keeps the
 * flat rates (RM 8.00 up to 1 kg, RM 2.00 per extra kg).
 */
trait CreatesRateCards
{
    /**
     * Publish the zone rates, in effect from the given moment (now by default).
     */
    protected function zoneRates(?CarbonInterface $from = null, string $name = 'Zone rates'): RateCard
    {
        return RateCard::factory()
            ->published($from)
            ->withRates(self::zones(), self::routes())
            ->create(['name' => $name]);
    }

    /**
     * Peninsular Malaysia, Sabah & Labuan, and Sarawak.
     *
     * @return list<array{code: string, name: string, states: list<string>}>
     */
    protected static function zones(): array
    {
        $east = ['Sabah', 'Labuan', 'Sarawak'];

        return [
            [
                'code' => 'peninsular-malaysia',
                'name' => 'Peninsular Malaysia',
                'states' => array_values(array_diff(array_column(MalaysianState::cases(), 'value'), $east)),
            ],
            ['code' => 'sabah-labuan', 'name' => 'Sabah & Labuan', 'states' => ['Sabah', 'Labuan']],
            ['code' => 'sarawak', 'name' => 'Sarawak', 'states' => ['Sarawak']],
        ];
    }

    /**
     * Every route between the three zones, with bands in grams and prices in sen.
     *
     * @return list<array{from: string, to: string, extraKgSen: int, bands: list<array{maxWeightG: int, priceSen: int}>}>
     */
    protected static function routes(): array
    {
        $route = fn (string $from, string $to, int $extraKgSen, array $bands): array => [
            'from' => $from,
            'to' => $to,
            'extraKgSen' => $extraKgSen,
            'bands' => array_map(fn (int $weight, int $price): array => ['maxWeightG' => $weight, 'priceSen' => $price], array_keys($bands), $bands),
        ];

        return [
            $route('peninsular-malaysia', 'peninsular-malaysia', 200, [1000 => 800]),
            $route('peninsular-malaysia', 'sabah-labuan', 500, [500 => 900, 1000 => 1200, 2000 => 1700, 5000 => 3100]),
            $route('peninsular-malaysia', 'sarawak', 450, [500 => 900, 1000 => 1150, 2000 => 1600, 5000 => 2900]),
            $route('sabah-labuan', 'peninsular-malaysia', 550, [500 => 1000, 1000 => 1300, 2000 => 1800, 5000 => 3300]),
            $route('sabah-labuan', 'sabah-labuan', 250, [1000 => 900, 3000 => 1300]),
            $route('sabah-labuan', 'sarawak', 350, [1000 => 1000, 3000 => 1500]),
            $route('sarawak', 'peninsular-malaysia', 450, [500 => 950, 1000 => 1250, 2000 => 1700, 5000 => 3000]),
            $route('sarawak', 'sabah-labuan', 350, [1000 => 1000, 3000 => 1500]),
            $route('sarawak', 'sarawak', 250, [1000 => 900, 3000 => 1300]),
        ];
    }
}
