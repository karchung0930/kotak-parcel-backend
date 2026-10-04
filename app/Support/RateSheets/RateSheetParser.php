<?php

namespace App\Support\RateSheets;

use App\Actions\RateCards\PublishRateCard;
use App\Enums\RateImportLayout;
use App\Http\Requests\Admin\UpdateRateCardRequest;
use App\Models\RateImport;
use App\Support\PriceList;

/**
 * Reads every row of a sheet with the confirmed mapping: weights to grams
 * and prices to sen, zones to the base card's codes, each route's bands and
 * price per extra kg. Every row is checked, and every problem is noted
 * with its row and column:
 *
 * - missing values (in a matrix, a box saying "n/a" means no band at that
 *   weight; an empty box is a missing price), and values that are not
 *   numbers;
 * - zones the base card does not have;
 * - the same band (or price per extra kg) twice on a route;
 * - prices that go down as the weight goes up. Equal prices are fine, as
 *   when publishing, so every card that can be published (and exported)
 *   imports back;
 * - a price per extra kg for another step than 1 kg ("Per 0.5 kg");
 * - weights above the limit, prices out of range;
 * - zone pairs without a route, routes without bands or a price per extra
 *   kg, routes with more bands than a rate card takes.
 *
 * With no problems, the routes are complete: what a published card needs.
 *
 * @phpstan-import-type Mapping from RateImport
 * @phpstan-import-type Route from PriceList
 */
final class RateSheetParser
{
    /**
     * The most rows of prices a sheet may have: 30 bands and an extra-kg
     * row for every route between 16 zones, with room to spare.
     */
    public const MAX_ROWS = 10000;

    /**
     * The bands found so far, by route ("from>to") and weight in grams:
     * the price in sen and where it was.
     *
     * @var array<string, array<int, array{int, int, int|null}>>
     */
    private array $bands = [];

    /**
     * The prices per extra kg found so far, by route: the price in sen and where it was.
     *
     * @var array<string, array{int, int, int|null}>
     */
    private array $extras = [];

    private Problems $problems;

    /**
     * Create a parser for prices between the given zones (codes, in the
     * base card's order). With decimal commas, "8,50" is 8.5 (Cells::number()).
     *
     * @param  list<string>  $codes
     */
    public function __construct(
        private ZoneMatcher $zones,
        private array $codes,
        private int $maxWeightG,
        private bool $decimalComma = false,
    ) {
        $this->problems = new Problems;
    }

    /**
     * Read the rows (keyed by row number) with the mapping.
     *
     * @param  iterable<int, list<string|int|float|null>>  $rows
     * @param  Mapping  $mapping
     * @return array{rows: int, routes: list<Route>, problems: Problems}
     */
    public function parse(iterable $rows, RateImportLayout $layout, array $mapping): array
    {
        $this->bands = [];
        $this->extras = [];
        $this->problems = new Problems;
        $read = 0;

        foreach ($rows as $number => $cells) {
            if ($number <= $mapping['header_row']) {
                continue;
            }

            if (++$read > self::MAX_ROWS) {
                $this->problems->add($number, null, 'The sheet has more than '.number_format(self::MAX_ROWS).' rows of prices. Remove the rows that are not prices, or split the file.');

                break;
            }

            $layout === RateImportLayout::Long
                ? $this->longRow($number, $cells, $mapping)
                : $this->matrixRow($number, $cells, $mapping);
        }

        if ($read === 0) {
            $this->problems->add(null, null, "There are no prices under the headings on row {$mapping['header_row']}.");

            return ['rows' => 0, 'routes' => [], 'problems' => $this->problems];
        }

        return ['rows' => min($read, self::MAX_ROWS), 'routes' => $this->routes($layout), 'problems' => $this->problems];
    }

    /**
     * Read one row of a long layout: origin, destination, max weight (or a
     * mark for the price per extra kg) and price.
     *
     * @param  list<string|int|float|null>  $cells
     * @param  Mapping  $mapping
     */
    private function longRow(int $number, array $cells, array $mapping): void
    {
        $columns = $mapping['columns'] ?? ['origin' => null, 'destination' => null, 'weight' => null, 'price' => null];
        $value = fn (string $role): string|int|float|null => $columns[$role] === null ? null : ($cells[$columns[$role]] ?? null);

        // A row without anything in the mapped columns (a note beside the table) is not a price.
        if (array_filter(array_keys($columns), fn (string $role): bool => ! Cells::isEmpty($value($role))) === []) {
            return;
        }

        $before = $this->problems->count();
        $origin = $this->zoneOf($value('origin'), $number, $columns['origin'], 'origin');
        $destination = $this->zoneOf($value('destination'), $number, $columns['destination'], 'destination');
        $isExtra = Cells::isExtraKg($value('weight'));
        $grams = $isExtra
            ? $this->checkExtraStep($value('weight'), $number, $columns['weight'])
            : $this->grams($value('weight'), $mapping['weight_unit'], $number, $columns['weight']);
        $sen = $this->sen($value('price'), $mapping['price_unit'], $number, $columns['price']);

        if ($this->problems->count() > $before || $origin === null || $destination === null || $sen === null) {
            return;
        }

        $this->record("{$origin}>{$destination}", $grams, $sen, $number, $columns['price']);
    }

    /**
     * Read one row of a matrix: the weight (or the price per extra kg row),
     * then a price for each route column. A box saying "n/a" (or "-")
     * means the route has no band at that weight; an empty box is a
     * missing price. A row without prices and without a weight is a label
     * or a note, and is not read.
     *
     * @param  list<string|int|float|null>  $cells
     * @param  Mapping  $mapping
     */
    private function matrixRow(int $number, array $cells, array $mapping): void
    {
        $weightColumn = $mapping['weight_column'] ?? 0;
        $routes = array_filter($mapping['routes'] ?? [], fn (array $route): bool => $route['origin'] !== null && $route['destination'] !== null);
        $weight = $cells[$weightColumn] ?? null;
        $extraRow = $mapping['extra_row'] ?? null;
        $isExtra = $extraRow === null ? Cells::isExtraKg($weight) : $extraRow === $number;

        $filled = array_filter($routes, fn (array $route): bool => ! Cells::isEmpty($cells[$route['column']] ?? null));

        if ($filled === [] && ($isExtra || Cells::number($weight, $this->decimalComma) === null)) {
            return;
        }

        $before = $this->problems->count();
        $grams = $isExtra
            ? $this->checkExtraStep($weight, $number, $weightColumn)
            : $this->grams($weight, $mapping['weight_unit'], $number, $weightColumn);

        if ($this->problems->count() > $before) {
            return;
        }

        foreach ($routes as $route) {
            $price = $cells[$route['column']] ?? null;

            if (Cells::isNoBand($price)) {
                continue;
            }

            $sen = $this->sen($price, $mapping['price_unit'], $number, $route['column']);

            if ($sen !== null) {
                $this->record("{$route['origin']}>{$route['destination']}", $grams, $sen, $number, $route['column']);
            }
        }
    }

    /**
     * Keep a band (or with no weight, a price per extra kg) unless the route
     * already has one there.
     */
    private function record(string $route, ?int $grams, int $sen, int $number, ?int $column): void
    {
        [$from, $to] = explode('>', $route);
        $name = $this->zones->routeName($from, $to);

        if ($grams === null) {
            if (isset($this->extras[$route])) {
                $this->problems->add($number, $column, "{$name} already has a price per extra kg, on row {$this->extras[$route][1]}.");

                return;
            }

            $this->extras[$route] = [$sen, $number, $column];

            return;
        }

        if (isset($this->bands[$route][$grams])) {
            $this->problems->add($number, $column, sprintf('%s already has a band up to %s, on row %d.', $name, PublishRateCard::kg($grams), $this->bands[$route][$grams][1]));

            return;
        }

        $this->bands[$route][$grams] = [$sen, $number, $column];
    }

    /**
     * Put the routes together in the base card's zone order, checking each
     * ordered pair of zones has one, with bands that never get cheaper, not
     * too many of them, and a price per extra kg.
     *
     * @return list<Route>
     */
    private function routes(RateImportLayout $layout): array
    {
        $routes = [];

        foreach ($this->codes as $from) {
            foreach ($this->codes as $to) {
                $key = "{$from}>{$to}";
                $name = $this->zones->routeName($from, $to);
                $bands = $this->bands[$key] ?? [];
                $extra = $this->extras[$key] ?? null;

                if ($bands === [] && $extra === null) {
                    $this->problems->add(null, null, "{$name}: there are no prices for this route.");

                    continue;
                }

                if ($bands === []) {
                    $this->problems->add(null, null, "{$name}: there is a price per extra kg but no weight bands.");
                }

                ksort($bands);
                $previous = null;

                foreach ($bands as $grams => [$sen, $number, $column]) {
                    if ($previous !== null && $sen < $previous[1]) {
                        $this->problems->add($number, $column, sprintf(
                            '%s: up to %s costs less than up to %s. Prices must not go down as the weight goes up.',
                            $name,
                            PublishRateCard::kg($grams),
                            PublishRateCard::kg($previous[0]),
                        ));
                    }

                    $previous = [$grams, $sen];
                }

                if (count($bands) > UpdateRateCardRequest::MAX_BANDS) {
                    $this->problems->add(null, null, sprintf('%s: %d weight bands; a rate card takes at most %d.', $name, count($bands), UpdateRateCardRequest::MAX_BANDS));
                }

                if ($extra === null) {
                    $this->problems->add(null, null, $layout === RateImportLayout::Long
                        ? "{$name}: there is no price per extra kg. Add a row with “Each additional kg” as its weight."
                        : "{$name}: there is no price per extra kg. Add a row “Each additional kg” under the bands, or choose the row that has them.");
                }

                $routes[] = [
                    'from' => $from,
                    'to' => $to,
                    'extraKgSen' => $extra[0] ?? 0,
                    'bands' => array_map(
                        fn (int $grams, array $band): array => ['maxWeightG' => $grams, 'priceSen' => $band[0]],
                        array_keys($bands),
                        $bands,
                    ),
                ];
            }
        }

        return $routes;
    }

    /**
     * Get the code of the zone named in a cell, noting a problem when the
     * cell is empty or names no zone of the base card.
     */
    private function zoneOf(string|int|float|null $value, int $number, ?int $column, string $role): ?string
    {
        if (Cells::isEmpty($value)) {
            $this->problems->add($number, $column, "The {$role} is empty.");

            return null;
        }

        $code = $this->zones->zone($value);

        if ($code === null) {
            $this->problems->add($number, $column, sprintf(
                '“%s” is not one of the zones (%s).',
                Cells::quote($value),
                implode(', ', array_map($this->zones->name(...), $this->codes)),
            ));
        }

        return $code;
    }

    /**
     * Check a price per extra kg is for each kg, noting a problem when its
     * cell says another step ("Per 0.5 kg"): the price rule adds it for
     * every kg started. Gives no weight, as the row is not a band.
     */
    private function checkExtraStep(string|int|float|null $label, int $number, ?int $column): null
    {
        $step = Cells::extraStepG($label);

        if ($step !== null && $step !== 1000) {
            $this->problems->add($number, $column, sprintf('The price per extra kg must be for each 1 kg; this row is for %s.', PublishRateCard::kg($step)));
        }

        return null;
    }

    /**
     * Get a weight in grams, noting a problem when it is missing, not a
     * number, not above zero, over the limit or finer than a gram. A unit
     * typed in the cell ("500 g") wins over the column's.
     *
     * @param  'kg'|'g'  $unit
     */
    private function grams(string|int|float|null $value, string $unit, int $number, ?int $column): ?int
    {
        if (Cells::isEmpty($value)) {
            $this->problems->add($number, $column, 'The weight is empty.');

            return null;
        }

        $parsed = Cells::number($value, $this->decimalComma);

        if ($parsed === null || in_array($parsed[1], ['rm', 'sen'], true)) {
            $this->problems->add($number, $column, sprintf(
                Cells::isAboveWeight($value)
                    ? '“%s” is not a weight. For the price of each kg above the bands, write “Each additional kg”.'
                    : '“%s” is not a weight.',
                Cells::quote($value),
            ));

            return null;
        }

        [$amount, $typed] = $parsed;
        $unit = $typed ?? $unit;

        if ($amount <= 0) {
            $this->problems->add($number, $column, 'A weight must be above 0.');

            return null;
        }

        $grams = $unit === 'kg' ? $amount * 1000 : $amount;

        // Compared before it becomes a whole number, which a huge value would not fit.
        if ($grams > $this->maxWeightG) {
            $this->problems->add($number, $column, sprintf('Up to %s is over the %s limit.', PublishRateCard::kg($grams), PublishRateCard::kg($this->maxWeightG)));

            return null;
        }

        if (abs($grams - round($grams)) > 0.000001) {
            $this->problems->add($number, $column, $unit === 'kg' ? 'Use at most 3 decimal places for kg.' : 'Grams must be whole numbers.');

            return null;
        }

        return (int) round($grams);
    }

    /**
     * Get a price in sen, noting a problem when it is missing, not a number,
     * below zero, above the most a price can be or finer than a sen. A unit
     * typed in the cell ("RM 8", "800 sen") wins over the column's.
     *
     * @param  'rm'|'sen'  $unit
     */
    private function sen(string|int|float|null $value, string $unit, int $number, ?int $column): ?int
    {
        if (Cells::isEmpty($value)) {
            $this->problems->add($number, $column, 'The price is empty.');

            return null;
        }

        $parsed = Cells::number($value, $this->decimalComma);

        if ($parsed === null || in_array($parsed[1], ['kg', 'g'], true)) {
            $this->problems->add($number, $column, sprintf('“%s” is not a price.', Cells::quote($value)));

            return null;
        }

        [$amount, $typed] = $parsed;
        $unit = $typed ?? $unit;

        if ($amount < 0) {
            $this->problems->add($number, $column, 'A price cannot be below zero.');

            return null;
        }

        $sen = $unit === 'rm' ? $amount * 100 : $amount;

        // Compared before it becomes a whole number, which a huge value would not fit.
        if ($sen > UpdateRateCardRequest::MAX_PRICE_SEN) {
            $this->problems->add($number, $column, 'A price can be at most RM 10,000.00.');

            return null;
        }

        if (abs($sen - round($sen)) > 0.000001) {
            $this->problems->add($number, $column, $unit === 'rm' ? 'Use at most 2 decimal places for ringgit.' : 'Sen must be whole numbers.');

            return null;
        }

        return (int) round($sen);
    }
}
