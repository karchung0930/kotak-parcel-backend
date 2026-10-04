<?php

namespace Tests\Feature\RateImports;

use App\Actions\RateImports\ReadRateImport;
use App\Enums\MalaysianState;
use App\Enums\RateImportLayout;
use App\Enums\RateImportStatus;
use App\Models\RateCard;
use App\Models\RateImport;
use App\Support\RateSheets\RateSheetParser;
use App\Support\RateSheets\SheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesRateCards;
use Tests\Concerns\WritesRateSheets;
use Tests\TestCase;
use ZipArchive;

/**
 * Reading an uploaded file: its sheets, the headings row, the layout and
 * the mapping suggested from the headings and the numbers under them.
 */
class ReadRateImportTest extends TestCase
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

    public function test_a_long_layout_is_found_by_its_headings()
    {
        $import = $this->read($this->xlsxFile(['Rates' => self::longZoneRates()]));

        $this->assertSame(RateImportStatus::NeedsMapping, $import->status);
        $this->assertSame(RateImportLayout::Long, $import->layout);
        $this->assertEquals([
            'header_row' => 1,
            'columns' => ['origin' => 0, 'destination' => 1, 'weight' => 2, 'price' => 3],
            'weight_unit' => 'kg',
            'price_unit' => 'rm',
        ], $import->mapping);
        $this->assertSame(['Rates'], $import->preview['sheets'] ?? null);
        $this->assertSame(35, $import->preview['last_row'] ?? null);
        $this->assertFalse($import->preview['cut_short'] ?? null);
        $this->assertSame(4, $import->preview['columns'] ?? null);
        $this->assertCount(15, $import->preview['rows'] ?? []);
        $this->assertEquals(['number' => 3, 'cells' => ['Peninsular Malaysia', 'Peninsular Malaysia', 'Each additional kg', '2']], $import->preview['rows'][2] ?? null);
        $this->assertNull($import->errors);
    }

    public function test_messy_headings_below_a_title_are_found_in_any_case()
    {
        $import = $this->read($this->xlsxFile(['Sheet1' => [
            ['KOTAK COURIER — RATE CARD 2027'],
            [],
            ['Valid from 1 January'],
            ['No.', 'FROM ZONE', 'notes', 'To', 'Max. Weight (g)', 'Rate (sen)'],
            [1, 'Peninsular', 'same day', 'Sabah', 500, 900],
            [2, 'Peninsular', null, 'Sabah', 1000, 1200],
            [3, 'Peninsular', null, 'Sabah', 'Additional kg', 500],
        ]]));

        $this->assertSame(RateImportLayout::Long, $import->layout);
        $this->assertSame(4, $import->mapping['header_row'] ?? null);
        $this->assertEquals(['origin' => 1, 'destination' => 3, 'weight' => 4, 'price' => 5], $import->mapping['columns'] ?? null);
        $this->assertSame('g', $import->mapping['weight_unit'] ?? null);
        $this->assertSame('sen', $import->mapping['price_unit'] ?? null);
        // The preview keeps the sheet's own row numbers, skipping the empty row.
        $this->assertSame([1, 3, 4, 5, 6, 7], array_column($import->preview['rows'] ?? [], 'number'));
    }

    /**
     * @return array<string, array{list<list<string|int|float|null>>, string, string}>
     */
    public static function unitsFromNumbers(): array
    {
        return [
            'kg and ringgit' => [[[0.5, 9.5], [1, 12]], 'kg', 'rm'],
            'grams and sen' => [[[500, 950], [1000, 1200]], 'g', 'sen'],
            'grams and whole ringgit' => [[[500, 9], [1000, 12]], 'g', 'rm'],
            'units typed in the cells' => [[['500 g', 'RM 9.50'], ['1 kg', 'RM 12']], 'g', 'rm'],
        ];
    }

    /**
     * @param  list<list<string|int|float|null>>  $bands
     */
    #[DataProvider('unitsFromNumbers')]
    public function test_units_come_from_the_numbers_when_the_headings_have_none(array $bands, string $weightUnit, string $priceUnit)
    {
        $rows = [['Origin', 'Destination', 'Weight', 'Price']];

        foreach ($bands as [$weight, $price]) {
            $rows[] = ['Sarawak', 'Sarawak', $weight, $price];
        }

        $import = $this->read($this->xlsxFile(['Rates' => $rows]));

        $this->assertSame($weightUnit, $import->mapping['weight_unit'] ?? null);
        $this->assertSame($priceUnit, $import->mapping['price_unit'] ?? null);
    }

    public function test_a_matrix_is_found_by_its_route_headings()
    {
        $import = $this->read($this->xlsxFile(['Grid' => [
            ['Prices in RM'],
            ['Weight (kg)', 'Peninsular → Sabah & Labuan', 'Peninsular Malaysia -> Sarawak', 'Within Sarawak', 'Sabah to Sarawak', 'Remarks'],
            ['Up to 0.5 kg', 9, 9, null, null, null],
            ['Up to 1 kg', 12, 11.5, 9, 10, 'promo'],
            ['Each additional kg', 5, 4.5, 2.5, 3.5, null],
        ]]));

        $this->assertSame(RateImportLayout::Matrix, $import->layout);
        $this->assertEquals([
            'header_row' => 2,
            'weight_column' => 0,
            'routes' => [
                ['column' => 1, 'origin' => 'peninsular-malaysia', 'destination' => 'sabah-labuan'],
                ['column' => 2, 'origin' => 'peninsular-malaysia', 'destination' => 'sarawak'],
                ['column' => 3, 'origin' => 'sarawak', 'destination' => 'sarawak'],
                ['column' => 4, 'origin' => 'sabah-labuan', 'destination' => 'sarawak'],
                // Not a route: left for the admin, read as nothing.
                ['column' => 5, 'origin' => null, 'destination' => null],
            ],
            'extra_row' => 5,
            'weight_unit' => 'kg',
            'price_unit' => 'rm',
        ], $import->mapping);
    }

    public function test_matrix_headings_match_west_and_east_to_the_zones_covering_them()
    {
        $base = RateCard::factory()->withRates(
            [
                ['code' => 'west-malaysia', 'name' => 'West Malaysia', 'states' => array_values(array_diff(array_column(MalaysianState::cases(), 'value'), ['Sabah', 'Sarawak', 'Labuan']))],
                ['code' => 'east-malaysia', 'name' => 'East Malaysia', 'states' => ['Sabah', 'Sarawak', 'Labuan']],
            ],
            [],
        )->create();

        $import = $this->read($this->xlsxFile(['Rates' => [
            ['Weight (g)', 'West - West', 'West - East', 'East - West', 'East - East'],
            [1000, 800, 1200, 1300, 900],
            ['Per kg after', 200, 500, 550, 250],
        ]]), $base);

        $this->assertSame(RateImportLayout::Matrix, $import->layout);
        $this->assertSame('g', $import->mapping['weight_unit'] ?? null);
        $this->assertSame('sen', $import->mapping['price_unit'] ?? null);
        $this->assertSame(3, $import->mapping['extra_row'] ?? null);
        $this->assertEquals(
            [['west-malaysia', 'west-malaysia'], ['west-malaysia', 'east-malaysia'], ['east-malaysia', 'west-malaysia'], ['east-malaysia', 'east-malaysia']],
            array_map(fn (array $route) => [$route['origin'], $route['destination']], $import->mapping['routes'] ?? []),
        );
    }

    public function test_a_csv_file_with_semicolons_is_read()
    {
        $import = $this->read($this->csvFile([
            ['Dari', 'Ke', 'Berat (kg)', 'Harga (RM)'],
            ['Sarawak', 'Sarawak', '1', '9.00'],
            ['Sarawak', 'Sarawak', 'Tambahan 1kg', '2.50'],
        ], ';'), name: 'rates.csv');

        $this->assertSame(RateImportStatus::NeedsMapping, $import->status);
        $this->assertSame(RateImportLayout::Long, $import->layout);
        $this->assertEquals(['origin' => 0, 'destination' => 1, 'weight' => 2, 'price' => 3], $import->mapping['columns'] ?? null);
        $this->assertNull($import->sheet);
        $this->assertSame([], $import->preview['sheets'] ?? null);
        $this->assertSame(['Sarawak', 'Sarawak', '1', '9.00'], $import->preview['rows'][1]['cells'] ?? null);
    }

    public function test_a_csv_file_saved_in_the_windows_encoding_is_read_as_utf8()
    {
        $path = $this->sheetPath('csv');
        // "–" is byte 0x96 in Windows-1252, which is not UTF-8 on its own.
        file_put_contents($path, "Origin,Destination,Weight,Price\r\nSabah \x96 Labuan,Sarawak,1,9\r\n");

        $import = $this->read($path, name: 'rates.csv');

        $this->assertSame(RateImportStatus::NeedsMapping, $import->status);
        $this->assertSame(['Sabah – Labuan', 'Sarawak', '1', '9'], $import->preview['rows'][1]['cells'] ?? null);
    }

    public function test_a_row_is_read_up_to_257_columns()
    {
        $import = $this->read($this->xlsxFile(['Rates' => [
            ['Origin', 'Destination', 'Weight', 'Price', ...array_fill(0, 296, 'note')],
            ['Sarawak', 'Sarawak', 1, 9],
        ]]));

        $this->assertSame(SheetReader::MAX_COLUMNS, $import->preview['columns'] ?? null);
        $this->assertCount(257, $import->preview['rows'][0]['cells'] ?? []);
    }

    public function test_a_long_sheet_is_read_only_as_far_as_the_check_reads()
    {
        $rows = [['Origin', 'Destination', 'Weight', 'Price'], ...array_fill(0, RateSheetParser::MAX_ROWS + 100, ['Sarawak', 'Sarawak', '1', '9'])];

        $import = $this->read($this->csvFile($rows), name: 'rates.csv');

        $this->assertSame(RateImportStatus::NeedsMapping, $import->status);
        // The headings within the preview's 30 rows, then 10,001 rows: enough to tell it has too many.
        $this->assertSame(10031, ReadRateImport::MAX_SCAN_ROWS);
        $this->assertSame(ReadRateImport::MAX_SCAN_ROWS, $import->preview['last_row'] ?? null);
        $this->assertTrue($import->preview['cut_short'] ?? null);
    }

    public function test_reading_stops_after_1000_empty_rows_in_a_row()
    {
        $import = $this->read($this->xlsxFile(['Rates' => [
            ['Origin', 'Destination', 'Weight', 'Price'],
            ['Sarawak', 'Sarawak', 1, 9],
            ...array_fill(0, SheetReader::MAX_EMPTY_ROWS, []),
            ['Sarawak', 'Sarawak', 'Each additional kg', 2.5],
        ]]));

        $this->assertSame(2, $import->preview['last_row'] ?? null);
        $this->assertFalse($import->preview['cut_short'] ?? null);
    }

    public function test_a_workbook_with_too_many_sheets_or_a_long_sheet_name_fails()
    {
        $sheets = [];

        foreach (range(1, ReadRateImport::MAX_SHEETS + 1) as $i) {
            $sheets["Sheet {$i}"] = [['Origin']];
        }

        $import = $this->read($this->xlsxFile($sheets));

        $this->assertSame(RateImportStatus::Failed, $import->status);
        $this->assertSame('The workbook has more than 50 sheets. Keep the sheet with the prices and upload it again.', $import->errors['items'][0]['message'] ?? null);
        $this->assertNull($import->preview);

        // Excel stops at 31 characters, but the file itself may say anything.
        $path = $this->xlsxFile(['Rates' => self::longZoneRates()]);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('xl/workbook.xml', str_replace('name="Rates"', 'name="'.str_repeat('R', 101).'"', (string) $zip->getFromName('xl/workbook.xml')));
        $zip->close();

        $import = $this->read($path);

        $this->assertSame(RateImportStatus::Failed, $import->status);
        $this->assertSame('A sheet name is longer than 100 characters. Shorten it and upload the file again.', $import->errors['items'][0]['message'] ?? null);
    }

    public function test_a_sheet_that_cannot_be_read_keeps_the_sheet_names_so_another_can_be_chosen()
    {
        $path = 'rate-imports/'.Str::uuid().'.xlsx';
        Storage::disk('local')->put($path, 'a sheet part that is not XML');
        // As ChooseRateImportSheet leaves it: the names known, another sheet to read.
        $import = RateImport::factory()->create([
            'path' => $path,
            'sheet' => 'Other',
            'base_rate_card_id' => $this->zones->id,
            'preview' => ['sheets' => ['Rates', 'Other'], 'rows' => [], 'columns' => 4, 'last_row' => 35],
        ]);

        app(ReadRateImport::class)->handle($import);
        $import->refresh();

        $this->assertSame(RateImportStatus::Failed, $import->status);
        $this->assertSame('The file could not be read. Open it in Excel, save it again as .xlsx or .csv and upload it.', $import->errors['items'][0]['message'] ?? null);
        $this->assertSame(['Rates', 'Other'], $import->preview['sheets'] ?? null);
        $this->assertTrue($import->canChooseSheet());
    }

    public function test_the_first_sheet_with_anything_in_it_is_read()
    {
        $import = $this->read($this->xlsxFile(['Cover' => [], 'Rates' => self::longZoneRates(), 'Zones' => [['Zone']]]));

        $this->assertSame('Rates', $import->sheet);
        $this->assertSame(['Cover', 'Rates', 'Zones'], $import->preview['sheets'] ?? null);
        $this->assertSame(RateImportLayout::Long, $import->layout);
    }

    public function test_headings_that_cannot_be_recognised_leave_the_mapping_to_the_admin()
    {
        $import = $this->read($this->xlsxFile(['Rates' => [['A', 'B', 'C'], ['x', 'y', 'z']]]));

        $this->assertSame(RateImportStatus::NeedsMapping, $import->status);
        $this->assertSame(RateImportLayout::Long, $import->layout);
        $this->assertSame(1, $import->mapping['header_row'] ?? null);
        $this->assertEquals(['origin' => null, 'destination' => null, 'weight' => null, 'price' => null], $import->mapping['columns'] ?? null);
    }

    public function test_a_file_that_is_not_a_spreadsheet_fails_with_a_plain_message()
    {
        $path = 'rate-imports/'.Str::uuid().'.xlsx';
        Storage::disk('local')->put($path, 'not a zip archive');
        $import = RateImport::factory()->create(['path' => $path, 'base_rate_card_id' => $this->zones->id]);

        app(ReadRateImport::class)->handle($import);
        $import->refresh();

        $this->assertSame(RateImportStatus::Failed, $import->status);
        $this->assertEquals([
            'total' => 1,
            'items' => [['row' => null, 'column' => null, 'message' => 'The file could not be read. Open it in Excel, save it again as .xlsx or .csv and upload it.']],
        ], $import->errors);
        $this->assertNull($import->layout);
    }

    public function test_an_empty_file_fails()
    {
        $import = $this->read($this->csvFile([]), name: 'rates.csv');

        $this->assertSame(RateImportStatus::Failed, $import->status);
        $this->assertSame('The file is empty.', $import->errors['items'][0]['message'] ?? null);
    }

    public function test_an_empty_sheet_chosen_by_the_admin_fails_but_another_can_be_chosen()
    {
        $import = $this->read($this->xlsxFile(['Rates' => self::longZoneRates(), 'Blank' => []]), sheet: 'Blank');

        $this->assertSame(RateImportStatus::Failed, $import->status);
        $this->assertSame('The sheet “Blank” is empty. Choose another sheet, or upload another file.', $import->errors['items'][0]['message'] ?? null);
        $this->assertTrue($import->canChooseSheet());
        $this->assertFalse($import->canBeMapped());
    }

    public function test_a_file_deleted_by_the_clean_up_or_a_deleted_base_card_fails()
    {
        $import = RateImport::factory()->create(['path' => null]);

        app(ReadRateImport::class)->handle($import);

        $this->assertSame('The file is no longer kept (files are deleted after 7 days). Upload it again.', $import->refresh()->errors['items'][0]['message'] ?? null);

        $draft = RateCard::factory()->withRates(self::zones(), [])->create();
        $import = $this->read($this->xlsxFile(['Rates' => self::longZoneRates()]), $draft, read: false);
        $draft->delete();

        app(ReadRateImport::class)->handle($import);

        $this->assertSame(RateImportStatus::Failed, $import->refresh()->status);
        $this->assertSame('The rates chosen for the zones were deleted or have no zones. Upload the file again and choose other rates.', $import->errors['items'][0]['message'] ?? null);
    }

    /**
     * Put a file on the private disk as an upload would, and read it.
     */
    private function read(string $file, ?RateCard $base = null, string $name = 'rates.xlsx', ?string $sheet = null, bool $read = true): RateImport
    {
        $path = 'rate-imports/'.Str::uuid().'.'.pathinfo($name, PATHINFO_EXTENSION);
        Storage::disk('local')->put($path, (string) file_get_contents($file));

        $import = RateImport::factory()->create([
            'path' => $path,
            'original_name' => $name,
            'sheet' => $sheet,
            'base_rate_card_id' => ($base ?? $this->zones)->id,
        ]);

        if ($read) {
            app(ReadRateImport::class)->handle($import);
        }

        return $import->refresh();
    }
}
