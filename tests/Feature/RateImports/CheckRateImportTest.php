<?php

namespace Tests\Feature\RateImports;

use App\Actions\RateImports\CheckRateImport;
use App\Enums\RateImportLayout;
use App\Enums\RateImportStatus;
use App\Models\RateCard;
use App\Models\RateImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesRateCards;
use Tests\Concerns\WritesRateSheets;
use Tests\TestCase;

/**
 * Checking every row with the confirmed mapping: conversion to grams and
 * sen, and each kind of problem with its row, column and message.
 */
class CheckRateImportTest extends TestCase
{
    use CreatesRateCards;
    use RefreshDatabase;
    use WritesRateSheets;

    private RateCard $zones;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->zones = $this->zoneRates();
    }

    public function test_a_complete_long_file_is_ready_with_every_route_in_grams_and_sen()
    {
        $import = $this->check($this->xlsxFile(['Rates' => self::longZoneRates()]), self::long());

        $this->assertSame(RateImportStatus::Ready, $import->status);
        $this->assertNull($import->errors);
        $this->assertSame(34, $import->summary['rows'] ?? null);
        $this->assertSame(25, $import->summary['bands'] ?? null);
        $this->assertEquals(self::routes(), $import->summary['routes'] ?? null);
    }

    public function test_grams_sen_and_units_typed_in_cells_are_converted()
    {
        $rows = [['From', 'To', 'Weight', 'Price']];

        foreach (self::zones() as $from) {
            foreach (self::zones() as $to) {
                $rows[] = [$from['name'], $to['name'], 500, 750];
                $rows[] = [$from['name'], $to['name'], '2 kg', 'RM 15.50'];
                $rows[] = [$from['name'], $to['name'], '1.01 - 3 kg', '2,000 sen'];
                $rows[] = [$from['name'], $to['name'], 'Additional kg', 250];
            }
        }

        $import = $this->check($this->xlsxFile(['Rates' => $rows]), self::long(weightUnit: 'g', priceUnit: 'sen'));

        $this->assertSame(RateImportStatus::Ready, $import->status, json_encode($import->errors) ?: '');
        $this->assertEquals([
            'from' => 'peninsular-malaysia',
            'to' => 'peninsular-malaysia',
            'extraKgSen' => 250,
            'bands' => [
                ['maxWeightG' => 500, 'priceSen' => 750],
                ['maxWeightG' => 2000, 'priceSen' => 1550],
                ['maxWeightG' => 3000, 'priceSen' => 2000],
            ],
        ], $import->summary['routes'][0] ?? null);
    }

    public function test_every_problem_is_listed_with_its_row_and_column()
    {
        $rows = self::longZoneRates();
        // Rows 2 and 3: Peninsular Malaysia within itself (1 kg band, extra kg).
        $rows[1] = ['Peninsular Malaysia', 'Peninsular Malaysia', null, 8];
        $rows[2] = ['Peninsular Malaysia', 'Peninsular Malaysia', 'Each additional kg', ''];
        // Rows 4 to 8: Peninsular Malaysia → Sabah & Labuan.
        $rows[3] = ['Peninsular Malaysia', 'Sabahh', 0.5, 9];
        $rows[4] = ['Peninsular Malaysia', 'Sabah & Labuan', 'one', 12];
        $rows[5] = ['Peninsular Malaysia', 'Sabah & Labuan', 2, 17];
        $rows[6] = ['Peninsular Malaysia', 'Sabah & Labuan', 2, 18];
        $rows[7] = ['', 'Sabah & Labuan', 'Each additional kg', 5];
        // Rows 9 to 13: Peninsular Malaysia → Sarawak.
        $rows[8] = ['Peninsular Malaysia', 'Sarawak', 0.5, 9];
        $rows[9] = ['Peninsular Malaysia', 'Sarawak', 1, 8.999];
        $rows[10] = ['Peninsular Malaysia', 'Sarawak', 2, 7];
        $rows[11] = ['Peninsular Malaysia', 'Sarawak', 31, 99];
        $rows[12] = ['Peninsular Malaysia', 'Sarawak', 0, -1];
        // Rows 36 and 37, after the rest: Sarawak → Peninsular Malaysia's extra kg again, and a bad row.
        $rows[] = ['Sarawak', 'Peninsular Malaysia', 'Per kg', 4.6];
        $rows[] = ['Sarawak', 'Peninsular Malaysia', 'Weight 3', 20000];

        $import = $this->check($this->xlsxFile(['Rates' => $rows]), self::long());

        $this->assertSame(RateImportStatus::Failed, $import->status);
        $this->assertSame([
            [2, 'C', 'The weight is empty.'],
            [3, 'D', 'The price is empty.'],
            [4, 'B', '“Sabahh” is not one of the zones (Peninsular Malaysia, Sabah & Labuan, Sarawak).'],
            [5, 'C', '“one” is not a weight.'],
            [7, 'D', 'Peninsular Malaysia → Sabah & Labuan already has a band up to 2 kg, on row 6.'],
            [8, 'A', 'The origin is empty.'],
            [10, 'D', 'Use at most 2 decimal places for ringgit.'],
            [11, 'D', 'Peninsular Malaysia → Sarawak: up to 2 kg costs less than up to 0.5 kg. Prices must not go down as the weight goes up.'],
            [12, 'C', 'Up to 31 kg is over the 30 kg limit.'],
            [13, 'C', 'A weight must be above 0.'],
            [13, 'D', 'A price cannot be below zero.'],
            [36, 'D', 'Sarawak → Peninsular Malaysia already has a price per extra kg, on row 29.'],
            [37, 'C', '“Weight 3” is not a weight.'],
            [37, 'D', 'A price can be at most RM 10,000.00.'],
            [null, null, 'Within Peninsular Malaysia: there are no prices for this route.'],
            [null, null, 'Peninsular Malaysia → Sabah & Labuan: there is no price per extra kg. Add a row with “Each additional kg” as its weight.'],
            [null, null, 'Peninsular Malaysia → Sarawak: there is no price per extra kg. Add a row with “Each additional kg” as its weight.'],
        ], array_map(fn (array $problem) => [$problem['row'], $problem['column'], $problem['message']], $import->errors['items'] ?? []));
        $this->assertSame(17, $import->errors['total'] ?? null);
        $this->assertSame([], $import->summary['routes'] ?? null);
    }

    public function test_zone_pairs_without_prices_and_routes_without_bands_are_problems()
    {
        $import = $this->check($this->xlsxFile(['Rates' => [
            ['Origin', 'Destination', 'Weight', 'Price'],
            ['Sarawak', 'Sarawak', 1, 9],
            ['Sarawak', 'Sarawak', 'Each additional kg', 2.5],
            ['Sabah', 'Sarawak', 'Each additional kg', 3.5],
        ]]), self::long());

        $messages = array_column($import->errors['items'] ?? [], 'message');

        $this->assertContains('Within Peninsular Malaysia: there are no prices for this route.', $messages);
        $this->assertContains('Sabah & Labuan → Sarawak: there is a price per extra kg but no weight bands.', $messages);
        $this->assertNotContains('Within Sarawak: there are no prices for this route.', $messages);
        $this->assertSame(8, $import->errors['total'] ?? null);
    }

    public function test_only_the_first_200_problems_are_kept_with_the_total()
    {
        $rows = [['Origin', 'Destination', 'Weight', 'Price']];

        for ($i = 0; $i < 300; $i++) {
            $rows[] = ['Nowhere', 'Sarawak', 1, 9];
        }

        $import = $this->check($this->csvFile($rows), self::long(), 'rates.csv');

        $this->assertSame(RateImportStatus::Failed, $import->status);
        $this->assertCount(200, $import->errors['items'] ?? []);
        // 300 unknown zones and 9 routes without prices.
        $this->assertSame(309, $import->errors['total'] ?? null);
        $this->assertSame(201, $import->errors['items'][199]['row'] ?? null);
    }

    public function test_reading_stops_after_10000_rows()
    {
        // Notes beside the table count as rows read, but are not prices.
        $rows = [['Origin', 'Destination', 'Weight', 'Price'], ...array_fill(0, 10001, [null, null, null, null, 'note'])];

        $import = $this->check($this->csvFile($rows), self::long(), 'rates.csv');

        $this->assertEquals(
            ['row' => 10002, 'column' => null, 'message' => 'The sheet has more than 10,000 rows of prices. Remove the rows that are not prices, or split the file.'],
            $import->errors['items'][0] ?? null,
        );
        $this->assertSame(10000, $import->summary['rows'] ?? null);
    }

    public function test_a_matrix_reads_n_a_or_a_dash_as_no_band_at_that_weight()
    {
        $names = array_column(self::zones(), 'name', 'code');
        $routes = self::routes();
        $weights = collect($routes)->flatMap(fn (array $route) => array_column($route['bands'], 'maxWeightG'))->unique()->sort()->values();
        $rows = [['Weight (kg)', ...array_map(fn (array $route) => "{$names[$route['from']]} → {$names[$route['to']]}", $routes)]];

        foreach ($weights as $grams) {
            $rows[] = [$grams / 1000, ...array_map(function (int $i, array $route) use ($grams) {
                $band = collect($route['bands'])->firstWhere('maxWeightG', $grams);

                return $band === null ? ($i % 2 === 0 ? 'n/a' : '-') : $band['priceSen'] / 100;
            }, array_keys($routes), $routes)];
        }

        $rows[] = ['Each additional kg', ...array_map(fn (array $route) => $route['extraKgSen'] / 100, $routes)];

        $mapping = [
            'header_row' => 1,
            'weight_column' => 0,
            'routes' => array_map(fn (int $i, array $route) => ['column' => $i + 1, 'origin' => $route['from'], 'destination' => $route['to']], array_keys($routes), $routes),
            'extra_row' => count($rows),
            'weight_unit' => 'kg',
            'price_unit' => 'rm',
        ];

        $import = $this->check($this->xlsxFile(['Matrix' => $rows]), $mapping, layout: RateImportLayout::Matrix);

        $this->assertSame(RateImportStatus::Ready, $import->status, json_encode($import->errors) ?: '');
        $this->assertEquals(self::routes(), $import->summary['routes'] ?? null);
    }

    public function test_matrix_problems_point_at_the_box()
    {
        $import = $this->check($this->xlsxFile(['Matrix' => [
            ['Weight (kg)', 'Sarawak → Sarawak', 'Sarawak → Peninsular Malaysia'],
            [1, 9, 'abc'],
            [null, 10, 12],
            [1, 11, 13],
            ['Each additional kg', 2.5, null],
        ]]), [
            'header_row' => 1,
            'weight_column' => 0,
            'routes' => [
                ['column' => 1, 'origin' => 'sarawak', 'destination' => 'sarawak'],
                ['column' => 2, 'origin' => 'sarawak', 'destination' => 'peninsular-malaysia'],
            ],
            'extra_row' => null,
            'weight_unit' => 'kg',
            'price_unit' => 'rm',
        ], layout: RateImportLayout::Matrix);

        $this->assertSame([
            [2, 'C', '“abc” is not a price.'],
            [3, 'A', 'The weight is empty.'],
            [4, 'B', 'Within Sarawak already has a band up to 1 kg, on row 2.'],
            [5, 'C', 'The price is empty.'],
        ], array_map(fn (array $problem) => [$problem['row'], $problem['column'], $problem['message']], array_slice($import->errors['items'] ?? [], 0, 4)));
        $this->assertContains(
            'Sarawak → Peninsular Malaysia: there is no price per extra kg. Add a row “Each additional kg” under the bands, or choose the row that has them.',
            array_column($import->errors['items'] ?? [], 'message'),
        );
    }

    public function test_an_empty_matrix_box_is_a_missing_price_not_a_missing_band()
    {
        $import = $this->check($this->xlsxFile(['Matrix' => [
            ['Weight (kg)', 'Within Peninsular Malaysia', 'Within Sarawak'],
            [0.5, 6, 'n/a'],
            [1, null, 9],
            [2, 10, 12],
            // A weight without any prices is missing them all.
            [3, null, null],
            ['Prices include SST', null, null],
            ['Each additional kg', 2, 2.5],
        ]]), [
            'header_row' => 1,
            'weight_column' => 0,
            'routes' => [
                ['column' => 1, 'origin' => 'peninsular-malaysia', 'destination' => 'peninsular-malaysia'],
                ['column' => 2, 'origin' => 'sarawak', 'destination' => 'sarawak'],
            ],
            'extra_row' => null,
            'weight_unit' => 'kg',
            'price_unit' => 'rm',
        ], layout: RateImportLayout::Matrix);

        $this->assertSame(RateImportStatus::Failed, $import->status);
        $this->assertSame([
            [3, 'B', 'The price is empty.'],
            [5, 'B', 'The price is empty.'],
            [5, 'C', 'The price is empty.'],
        ], array_map(fn (array $problem) => [$problem['row'], $problem['column'], $problem['message']], array_values(array_filter($import->errors['items'] ?? [], fn (array $problem) => $problem['row'] !== null))));
    }

    public function test_a_csv_file_separated_by_semicolons_may_write_decimals_with_a_comma()
    {
        $rows = array_map(
            fn (array $row) => array_map(fn (string|int|float|null $value) => is_string($value) ? $value : str_replace('.', ',', (string) $value), $row),
            self::longZoneRates(),
        );

        $import = $this->check($this->csvFile($rows, ';'), self::long(), 'rates.csv');

        $this->assertSame(RateImportStatus::Ready, $import->status, json_encode($import->errors) ?: '');
        $this->assertEquals(self::routes(), $import->summary['routes'] ?? null);
        // "0,5" is half a kg and "8,50" RM 8.50, not 5 kg and RM 850.
        $this->assertEquals(['maxWeightG' => 500, 'priceSen' => 900], $import->summary['routes'][1]['bands'][0] ?? null);
    }

    public function test_a_decimal_comma_elsewhere_is_not_a_number()
    {
        $rows = self::longZoneRates();
        $rows[1] = ['Peninsular Malaysia', 'Peninsular Malaysia', '0,5', '8,50'];
        $rows[2] = ['Peninsular Malaysia', 'Peninsular Malaysia', 'Each additional kg', '1,200.50'];

        $import = $this->check($this->xlsxFile(['Rates' => $rows]), self::long());

        $this->assertSame([
            [2, 'C', '“0,5” is not a weight.'],
            [2, 'D', '“8,50” is not a price.'],
        ], array_map(fn (array $problem) => [$problem['row'], $problem['column'], $problem['message']], array_slice($import->errors['items'] ?? [], 0, 2)));
        // A comma grouping thousands is read: RM 1,200.50 per extra kg is a price, so row 3 has no problem.
        $this->assertNotContains(3, array_column($import->errors['items'] ?? [], 'row'));
    }

    public function test_a_price_per_extra_kg_must_be_for_each_kg()
    {
        $rows = self::longZoneRates();
        $rows[2] = ['Peninsular Malaysia', 'Peninsular Malaysia', 'Each additional 0.5 kg', 1];
        $rows[6] = ['Peninsular Malaysia', 'Sabah & Labuan', 'Per 500g', 2.5];
        $rows[11] = ['Peninsular Malaysia', 'Sarawak', 'Over 5 kg', 4.5];
        // "Additional 1kg" is each kg, as "Each additional kg" is.
        $rows[17] = ['Sabah & Labuan', 'Peninsular Malaysia', 'Additional 1kg', 5.5];

        $import = $this->check($this->xlsxFile(['Rates' => $rows]), self::long());

        $this->assertSame([
            [3, 'C', 'The price per extra kg must be for each 1 kg; this row is for 0.5 kg.'],
            [7, 'C', 'The price per extra kg must be for each 1 kg; this row is for 0.5 kg.'],
            [12, 'C', '“Over 5 kg” is not a weight. For the price of each kg above the bands, write “Each additional kg”.'],
        ], array_map(fn (array $problem) => [$problem['row'], $problem['column'], $problem['message']], array_values(array_filter($import->errors['items'] ?? [], fn (array $problem) => $problem['row'] !== null))));

        $import = $this->check($this->xlsxFile(['Matrix' => [
            ['Weight (kg)', 'Within Sarawak'],
            [1, 9],
            ['Next 0.5kg', 1.25],
        ]]), [
            'header_row' => 1,
            'weight_column' => 0,
            'routes' => [['column' => 1, 'origin' => 'sarawak', 'destination' => 'sarawak']],
            'extra_row' => 3,
            'weight_unit' => 'kg',
            'price_unit' => 'rm',
        ], layout: RateImportLayout::Matrix);

        $this->assertEquals(
            ['row' => 3, 'column' => 'A', 'message' => 'The price per extra kg must be for each 1 kg; this row is for 0.5 kg.'],
            $import->errors['items'][0] ?? null,
        );
    }

    public function test_weights_written_as_the_first_band_or_with_and_below_are_read()
    {
        $rows = self::longZoneRates();
        $rows[1] = ['Peninsular Malaysia', 'Peninsular Malaysia', 'First 1kg', 8];
        $rows[3] = ['Peninsular Malaysia', 'Sabah & Labuan', '0.5kg & below', 9];
        $rows[4] = ['Peninsular Malaysia', 'Sabah & Labuan', '1 kg and below', 12];

        $import = $this->check($this->xlsxFile(['Rates' => $rows]), self::long());

        $this->assertSame(RateImportStatus::Ready, $import->status, json_encode($import->errors) ?: '');
        $this->assertEquals(self::routes(), $import->summary['routes'] ?? null);
    }

    public function test_huge_numbers_are_over_the_limit_rather_than_wrapping_round()
    {
        $rows = self::longZoneRates();
        $rows[1] = ['Peninsular Malaysia', 'Peninsular Malaysia', 1e16, 8];
        $rows[3] = ['Peninsular Malaysia', 'Sabah & Labuan', 0.5, 1e17];

        $import = $this->check($this->xlsxFile(['Rates' => $rows]), self::long());

        $this->assertSame(RateImportStatus::Failed, $import->status);
        $this->assertSame([
            [2, 'C', 'Up to 10000000000000000 kg is over the 30 kg limit.'],
            [4, 'D', 'A price can be at most RM 10,000.00.'],
        ], array_map(fn (array $problem) => [$problem['row'], $problem['column'], $problem['message']], array_slice($import->errors['items'] ?? [], 0, 2)));
    }

    public function test_a_route_takes_at_most_30_bands()
    {
        $rows = self::longZoneRates();
        // Peninsular Malaysia within itself: 31 bands (every half kg to 15.5 kg) instead of one.
        $bands = array_map(fn (int $i) => ['Peninsular Malaysia', 'Peninsular Malaysia', $i * 0.5, 8 + $i], range(1, 31));
        array_splice($rows, 1, 1, $bands);

        $import = $this->check($this->xlsxFile(['Rates' => $rows]), self::long());

        $this->assertSame(RateImportStatus::Failed, $import->status);
        $this->assertEquals(
            ['total' => 1, 'items' => [['row' => null, 'column' => null, 'message' => 'Within Peninsular Malaysia: 31 weight bands; a rate card takes at most 30.']]],
            $import->errors,
        );
    }

    public function test_a_sheet_without_rows_under_the_headings_is_a_problem()
    {
        $import = $this->check($this->xlsxFile(['Rates' => [['Origin', 'Destination', 'Weight', 'Price']]]), self::long());

        $this->assertSame(RateImportStatus::Failed, $import->status);
        $this->assertEquals(['total' => 1, 'items' => [['row' => null, 'column' => null, 'message' => 'There are no prices under the headings on row 1.']]], $import->errors);
    }

    public function test_an_import_no_longer_waiting_for_a_check_is_left_alone()
    {
        $import = $this->check($this->xlsxFile(['Rates' => self::longZoneRates()]), self::long(), check: false);
        $import->forceFill(['status' => RateImportStatus::NeedsMapping])->save();

        app(CheckRateImport::class)->handle($import);

        $this->assertSame(RateImportStatus::NeedsMapping, $import->refresh()->status);
        $this->assertNull($import->summary);
    }

    /**
     * A long mapping of four columns in the usual order.
     *
     * @return array<string, mixed>
     */
    private static function long(string $weightUnit = 'kg', string $priceUnit = 'rm'): array
    {
        return [
            'header_row' => 1,
            'columns' => ['origin' => 0, 'destination' => 1, 'weight' => 2, 'price' => 3],
            'weight_unit' => $weightUnit,
            'price_unit' => $priceUnit,
        ];
    }

    /**
     * Put a file on the private disk with a confirmed mapping, and check it.
     *
     * @param  array<string, mixed>  $mapping
     */
    private function check(string $file, array $mapping, string $name = 'rates.xlsx', RateImportLayout $layout = RateImportLayout::Long, bool $check = true): RateImport
    {
        $path = 'rate-imports/'.Str::uuid().'.'.pathinfo($name, PATHINFO_EXTENSION);
        Storage::disk('local')->put($path, (string) file_get_contents($file));

        $import = RateImport::factory()->status(RateImportStatus::Validating)->create([
            'path' => $path,
            'original_name' => $name,
            'base_rate_card_id' => $this->zones->id,
            'layout' => $layout,
            'mapping' => $mapping,
            'preview' => ['sheets' => [], 'rows' => [], 'columns' => 4, 'last_row' => 1],
        ]);

        if ($check) {
            app(CheckRateImport::class)->handle($import);
        }

        return $import->refresh();
    }
}
