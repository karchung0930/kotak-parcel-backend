<?php

namespace App\Support\RateSheets;

use App\Enums\RateImportLayout;
use App\Models\RateImport;

/**
 * Finds the headings row in the first rows of a sheet and suggests how to
 * read it, for the admin to check:
 *
 * - Long: columns for the origin, destination, max weight and price, found
 *   by their headings in any case ("From", "To zone", "Max weight (kg)",
 *   "Rate (RM)"…). A row whose weight says "additional kg" or "per kg"
 *   gives the route's price per extra kg.
 * - Matrix: weights down the first column and a heading per route, such as
 *   "Peninsular → Sabah & Labuan" or "West - East", matched to the base
 *   card's zones (ZoneMatcher). A row whose weight says "additional kg"
 *   holds the prices per extra kg.
 *
 * Units come from the headings ("(g)", "(sen)", "RM"), else from the
 * numbers: weights above the weight limit in kg must be grams, and whole
 * prices of 100 or more are taken as sen.
 *
 * @phpstan-import-type Mapping from RateImport
 */
final class LayoutDetector
{
    /**
     * How many rows from the top the headings are looked for in.
     */
    public const HEADING_ROWS = 20;

    /**
     * Words in a heading that say what a long layout's column holds. A
     * heading matching several is given to the longest match ("Up to"
     * makes a weight, not a destination).
     */
    private const ROLES = [
        'origin' => ['origin', 'from', 'origin zone', 'from zone', 'zone from', 'source', 'ship from', 'sender zone', 'pickup zone', 'dari', 'asal'],
        'destination' => ['destination', 'to', 'to zone', 'zone to', 'dest', 'ship to', 'deliver to', 'delivery zone', 'receiver zone', 'ke', 'destinasi', 'tujuan'],
        'weight' => ['weight', 'max weight', 'maximum weight', 'weight band', 'band', 'up to', 'max kg', 'kg', 'g', 'gram', 'grams', 'berat'],
        'price' => ['price', 'rate', 'cost', 'charge', 'amount', 'fee', 'tariff', 'rm', 'myr', 'sen', 'ringgit', 'harga'],
    ];

    /**
     * Create a new detector. With decimal commas, "8,50" is 8.5 (Cells::number()).
     */
    public function __construct(
        private ZoneMatcher $zones,
        private int $maxWeightG,
        private bool $decimalComma = false,
    ) {}

    /**
     * Suggest the layout and mapping of a sheet from its first rows (keyed by
     * row number). Rows anywhere in the sheet that mark a price per extra kg
     * are given by row number, with the columns that say so.
     *
     * @param  array<int, list<string|int|float|null>>  $rows
     * @param  array<int, list<int>>  $extraRows
     * @return array{RateImportLayout, Mapping}
     */
    public function detect(array $rows, array $extraRows = []): array
    {
        $partial = null;

        foreach (array_slice($rows, 0, self::HEADING_ROWS, true) as $number => $cells) {
            $columns = $this->longColumns($cells);
            $found = count(array_filter($columns, fn (?int $column): bool => $column !== null));

            if ($found === 4) {
                return [RateImportLayout::Long, $this->longMapping($number, $columns, $rows)];
            }

            $matrix = $this->matrixMapping($number, $cells, $rows, $extraRows);

            if ($matrix !== null) {
                return [RateImportLayout::Matrix, $matrix];
            }

            if ($found >= 2 && ($partial === null || $found > $partial[1])) {
                $partial = [$number, $found, $columns];
            }
        }

        // Nothing certain: the best guess, or the first row as headings, for the admin to finish.
        $number = $partial[0] ?? (int) (array_key_first($rows) ?? 1);
        $columns = $partial[2] ?? ['origin' => null, 'destination' => null, 'weight' => null, 'price' => null];

        return [RateImportLayout::Long, $this->longMapping($number, $columns, $rows)];
    }

    /**
     * Find a long layout's columns among a row's headings.
     *
     * @param  list<string|int|float|null>  $cells
     * @return array{origin: int|null, destination: int|null, weight: int|null, price: int|null}
     */
    private function longColumns(array $cells): array
    {
        $claims = [];

        foreach ($cells as $column => $value) {
            if (! is_string($value)) {
                continue;
            }

            $words = ' '.Cells::words($value).' ';

            foreach (self::ROLES as $role => $phrases) {
                $length = 0;

                foreach ($phrases as $phrase) {
                    if (str_contains($words, " {$phrase} ")) {
                        $length = max($length, substr_count($phrase, ' ') + 1);
                    }
                }

                if ($length > 0) {
                    $claims[] = [$role, $column, $length];
                }
            }
        }

        // The longest matches first, then the leftmost column.
        usort($claims, fn (array $a, array $b): int => [$b[2], $a[1]] <=> [$a[2], $b[1]]);

        $columns = ['origin' => null, 'destination' => null, 'weight' => null, 'price' => null];
        $taken = [];

        foreach ($claims as [$role, $column]) {
            if ($columns[$role] === null && ! isset($taken[$column])) {
                $columns[$role] = $column;
                $taken[$column] = true;
            }
        }

        return $columns;
    }

    /**
     * Suggest the mapping of a long layout with its headings on the given row.
     *
     * @param  array{origin: int|null, destination: int|null, weight: int|null, price: int|null}  $columns
     * @param  array<int, list<string|int|float|null>>  $rows
     * @return Mapping
     */
    private function longMapping(int $headingRow, array $columns, array $rows): array
    {
        $headings = $rows[$headingRow] ?? [];
        $data = array_filter($rows, fn (int $number): bool => $number > $headingRow, ARRAY_FILTER_USE_KEY);

        return [
            'header_row' => $headingRow,
            'columns' => $columns,
            'weight_unit' => $this->weightUnit($columns['weight'] === null ? '' : Cells::text($headings[$columns['weight']] ?? null), $data, $columns['weight'] === null ? [] : [$columns['weight']]),
            'price_unit' => $this->priceUnit($columns['price'] === null ? '' : Cells::text($headings[$columns['price']] ?? null), $data, $columns['price'] === null ? [] : [$columns['price']]),
        ];
    }

    /**
     * Suggest the mapping of a matrix with its headings on the given row, or
     * null when the row does not read as route headings: a weight column,
     * then headings of which at least half name two zones.
     *
     * @param  list<string|int|float|null>  $cells
     * @param  array<int, list<string|int|float|null>>  $rows
     * @param  array<int, list<int>>  $extraRows
     * @return Mapping|null
     */
    private function matrixMapping(int $headingRow, array $cells, array $rows, array $extraRows): ?array
    {
        $headings = array_filter($cells, fn (string|int|float|null $value): bool => ! Cells::isEmpty($value));
        $firstRoute = null;
        $routes = [];

        foreach ($headings as $column => $value) {
            $pair = is_string($value) ? $this->zones->route($value) : null;
            $firstRoute ??= $pair === null ? null : $column;
            $routes[] = ['column' => $column, 'origin' => $pair[0] ?? null, 'destination' => $pair[1] ?? null];
        }

        // The weights are in the column before the first route (its heading may be empty).
        if ($firstRoute === null || $firstRoute === 0) {
            return null;
        }

        $weightColumn = $firstRoute - 1;
        $routes = array_values(array_filter($routes, fn (array $route): bool => $route['column'] > $weightColumn));
        $matched = count(array_filter($routes, fn (array $route): bool => $route['origin'] !== null));

        if ($matched * 2 < count($routes)) {
            return null;
        }

        $data = array_filter($rows, fn (int $number): bool => $number > $headingRow, ARRAY_FILTER_USE_KEY);
        $above = array_filter($rows, fn (int $number): bool => $number <= $headingRow, ARRAY_FILTER_USE_KEY);
        $extraRow = null;

        foreach ($extraRows as $number => $columns) {
            if ($number > $headingRow && in_array($weightColumn, $columns, true)) {
                $extraRow = $number;

                break;
            }
        }

        return [
            'header_row' => $headingRow,
            'weight_column' => $weightColumn,
            'routes' => $routes,
            'extra_row' => $extraRow,
            'weight_unit' => $this->weightUnit(Cells::text($cells[$weightColumn] ?? null), $data, [$weightColumn]),
            // A unit for the prices is usually in a title above the grid, or in the headings.
            'price_unit' => $this->priceUnit(
                implode(' ', array_map(fn (array $row): string => implode(' ', array_map(Cells::text(...), $row)), $above)),
                $data,
                array_column($routes, 'column'),
            ),
        ];
    }

    /**
     * Work out the weight unit from a heading, else from the numbers in the
     * given columns: any above the limit in kg means grams.
     *
     * @param  array<int, list<string|int|float|null>>  $data
     * @param  list<int>  $columns
     * @return 'kg'|'g'
     */
    private function weightUnit(string $heading, array $data, array $columns): string
    {
        $words = ' '.Cells::words($heading).' ';

        if (preg_match('/ (g|gm|gram|grams) /', $words) === 1) {
            return 'g';
        }

        if (preg_match('/ (kg|kgs|kilo|kilos|kilogram|kilograms) /', $words) === 1) {
            return 'kg';
        }

        foreach ($this->numbers($data, $columns) as [$value, $unit]) {
            if ($unit === 'g' || $unit === 'kg') {
                return $unit;
            }

            if ($value > $this->maxWeightG / 1000) {
                return 'g';
            }
        }

        return 'kg';
    }

    /**
     * Work out the price unit from a heading, else from the numbers in the
     * given columns: whole numbers of 100 or more throughout mean sen.
     *
     * @param  array<int, list<string|int|float|null>>  $data
     * @param  list<int>  $columns
     * @return 'rm'|'sen'
     */
    private function priceUnit(string $heading, array $data, array $columns): string
    {
        $words = ' '.Cells::words($heading).' ';

        if (str_contains($words, ' sen ')) {
            return 'sen';
        }

        if (preg_match('/ (rm|myr|ringgit) /', $words) === 1) {
            return 'rm';
        }

        $numbers = $this->numbers($data, $columns);

        foreach ($numbers as [, $unit]) {
            if ($unit === 'rm' || $unit === 'sen') {
                return $unit;
            }
        }

        $values = array_column($numbers, 0);
        $whole = $values !== [] && array_filter($values, fn (float $value): bool => floor($value) !== $value) === [];

        return $whole && min($values) >= 100 ? 'sen' : 'rm';
    }

    /**
     * Get the numbers in the given columns of the data rows, with any unit
     * typed beside them.
     *
     * @param  array<int, list<string|int|float|null>>  $data
     * @param  list<int>  $columns
     * @return list<array{float, 'kg'|'g'|'rm'|'sen'|null}>
     */
    private function numbers(array $data, array $columns): array
    {
        $numbers = [];

        foreach ($data as $cells) {
            foreach ($columns as $column) {
                $number = Cells::number($cells[$column] ?? null, $this->decimalComma);

                if ($number !== null && ! Cells::isExtraKg($cells[$column] ?? null)) {
                    $numbers[] = $number;
                }
            }
        }

        return $numbers;
    }
}
