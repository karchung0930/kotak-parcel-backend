<?php

namespace Tests\Feature\RateImports;

use App\Enums\MalaysianState;
use App\Enums\Role;
use App\Models\RateCard;
use App\Models\RateCardBand;
use App\Models\RateCardRoute;
use App\Models\User;
use App\Support\RateSheets\SheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Concerns\CreatesRateCards;
use Tests\Concerns\WritesRateSheets;
use Tests\TestCase;

/**
 * Downloading a version as a workbook or CSV file, and reading it back in
 * as the same card.
 */
class RateCardExportTest extends TestCase
{
    use CreatesRateCards;
    use RefreshDatabase;
    use WritesRateSheets;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->admin = User::factory()->admin()->create();
    }

    public function test_a_version_downloads_as_a_workbook_with_rates_matrix_and_zones_sheets()
    {
        $card = $this->zoneRates();

        $response = $this->actingAs($this->admin)->get(route('admin.rates.download', [$card, 'xlsx']));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertDownload('kotak-rates-zone-rates.xlsx');

        $reader = $this->readerFor($response, 'xlsx');

        $this->assertSame(['Rates', 'Matrix', 'Zones'], $reader->sheetNames());

        $rates = iterator_to_array($reader->rows('Rates'));
        $this->assertCount(35, $rates);
        $this->assertSame(['Origin', 'Destination', 'Max weight (kg)', 'Price (RM)'], $rates[1]);
        $this->assertSame(['Peninsular Malaysia', 'Peninsular Malaysia', 1, 8], $rates[2]);
        $this->assertSame(['Peninsular Malaysia', 'Peninsular Malaysia', 'Each additional kg', 2], $rates[3]);
        $this->assertSame(['Peninsular Malaysia', 'Sabah & Labuan', 0.5, 9], $rates[4]);

        $matrix = iterator_to_array($reader->rows('Matrix'));
        $this->assertSame('Weight (kg)', $matrix[1][0]);
        $this->assertSame('Peninsular Malaysia → Sabah & Labuan', $matrix[1][2]);
        // A route without a band at a weight says so; an empty box would be a missing price.
        $this->assertSame([0.5, 'n/a', 9, 9, 10, 'n/a', 'n/a', 9.5, 'n/a', 'n/a'], $matrix[2]);
        $this->assertSame(['Each additional kg', 2, 5, 4.5, 5.5, 2.5, 3.5, 4.5, 3.5, 2.5], $matrix[7]);

        $zones = iterator_to_array($reader->rows('Zones'));
        $this->assertSame(['Zone', 'Code', 'States'], $zones[1]);
        $this->assertSame(['Sabah & Labuan', 'sabah-labuan', 'Sabah, W.P. Labuan'], $zones[3]);
    }

    public function test_a_version_downloads_as_csv_with_a_row_per_band()
    {
        $card = RateCard::factory()->flat()->create(['name' => 'Flat']);

        $response = $this->actingAs($this->admin)->get(route('admin.rates.download', [$card, 'csv']));

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertDownload('kotak-rates-flat.csv');

        $this->assertSame([
            1 => ['Origin', 'Destination', 'Max weight (kg)', 'Price (RM)'],
            2 => ['Malaysia', 'Malaysia', '1', '8.00'],
            3 => ['Malaysia', 'Malaysia', 'Each additional kg', '2.00'],
        ], iterator_to_array($this->readerFor($response, 'csv')->rows()));
    }

    public function test_text_that_a_spreadsheet_would_run_as_a_formula_is_escaped()
    {
        $card = RateCard::factory()->withRates(
            [
                ['code' => 'hyperlink', 'name' => '=HYPERLINK("http://example.com","x")', 'states' => array_values(array_diff(array_column(MalaysianState::cases(), 'value'), ['Sabah']))],
                ['code' => 'sum', 'name' => '@SUM(1+1)', 'states' => ['Sabah']],
            ],
            [
                ['from' => 'hyperlink', 'to' => 'sum', 'extraKgSen' => 200, 'bands' => [['maxWeightG' => 1000, 'priceSen' => 800]]],
            ],
        )->create(['name' => '+cmd|calc']);

        foreach (['xlsx', 'csv'] as $format) {
            $response = $this->actingAs($this->admin)->get(route('admin.rates.download', [$card, $format]));
            $rows = iterator_to_array($this->readerFor($response, $format)->rows($format === 'xlsx' ? 'Rates' : null));

            $this->assertSame('\'=HYPERLINK("http://example.com","x")', $rows[2][0], $format);
            $this->assertSame("'@SUM(1+1)", $rows[2][1], $format);
        }

        $response = $this->actingAs($this->admin)->get(route('admin.rates.download', [$card, 'xlsx']));
        $zones = iterator_to_array($this->readerFor($response, 'xlsx')->rows('Zones'));

        $this->assertSame("'@SUM(1+1)", $zones[3][0]);
        // The file name has no formula characters at all.
        $response->assertDownload('kotak-rates-cmdcalc.xlsx');
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function roundTrips(): array
    {
        return [
            'workbook, Rates sheet' => ['xlsx', null],
            'workbook, Matrix sheet' => ['xlsx', 'Matrix'],
            'a csv file' => ['csv', null],
        ];
    }

    #[DataProvider('roundTrips')]
    public function test_an_exported_version_imports_back_as_an_identical_draft(string $format, ?string $sheet)
    {
        $card = $this->zoneRates();
        $card->forceFill(['volumetric_divisor' => 6000])->save();

        $response = $this->actingAs($this->admin)->get(route('admin.rates.download', [$card, $format]));
        $this->readerFor($response, $format);
        $copy = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kotak-test-'.Str::random(12).".{$format}";
        copy($response->baseResponse->getFile()->getPathname(), $copy);
        $this->beforeApplicationDestroyed(fn () => @unlink($copy));

        $import = $this->uploadAs($this->admin, $copy, "export.{$format}", $card);

        if ($sheet !== null) {
            $this->actingAs($this->admin)->put(route('admin.rates.imports.sheet', $import), ['sheet' => $sheet])->assertSessionHasNoErrors();
            $import->refresh();
        }

        // The suggested mapping is confirmed as it is.
        $this->actingAs($this->admin)
            ->put(route('admin.rates.imports.mapping', $import), ['layout' => $import->layout?->value, ...($import->mapping ?? [])])
            ->assertSessionHasNoErrors();
        $this->assertSame('ready', $import->refresh()->status->value, json_encode($import->errors) ?: '');

        $this->actingAs($this->admin)->post(route('admin.rates.imports.draft', $import))->assertSessionHasNoErrors();
        $draft = RateCard::query()->findOrFail($import->refresh()->rate_card_id);

        $this->assertSame(6000, $draft->volumetric_divisor);
        $this->assertSame($this->shape($card), $this->shape($draft));
    }

    public function test_only_admins_download_rates()
    {
        $card = RateCard::query()->sole();

        foreach ([Role::Staff, Role::Driver, Role::Customer] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('admin.rates.download', [$card, 'xlsx']))
                ->assertForbidden();
        }

        auth()->logout();
        $this->get(route('admin.rates.download', [$card, 'csv']))->assertRedirect(route('login'));
        $this->actingAs($this->admin)->get("/admin/rates/{$card->id}/download/xls")->assertNotFound();
    }

    /**
     * Get what a card prices: its zones, and each route's zones, bands and
     * price per extra kg.
     *
     * @return array<string, mixed>
     */
    private function shape(RateCard $card): array
    {
        $card->load(['zones', 'routes.bands']);
        $codes = $card->zones->pluck('code', 'id');

        return [
            'zones' => $card->zones->map->only(['code', 'name', 'states'])->all(),
            'routes' => $card->routes
                ->map(fn (RateCardRoute $route) => [
                    $codes[$route->origin_zone_id],
                    $codes[$route->destination_zone_id],
                    $route->extra_kg_sen,
                    $route->bands->map(fn (RateCardBand $band) => [$band->max_weight_g, $band->price_sen])->all(),
                ])
                ->sortBy(fn (array $route) => "{$route[0]}>{$route[1]}")
                ->values()
                ->all(),
        ];
    }

    /**
     * Read a downloaded file.
     *
     * @param  'xlsx'|'csv'  $format
     */
    private function readerFor(TestResponse $response, string $format): SheetReader
    {
        $file = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $file);
        $path = $file->getFile()->getPathname();

        // The file is deleted once sent, which a test response never is.
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return new SheetReader($path, $format);
    }
}
