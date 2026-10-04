<?php

namespace Tests\Feature\RateImports;

use App\Actions\RateCards\PublishRateCard;
use App\Enums\RateCardStatus;
use App\Enums\RateImportStatus;
use App\Models\RateCard;
use App\Models\RateCardBand;
use App\Models\RateCardRoute;
use App\Models\RateCardZone;
use App\Models\RateImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesRateCards;
use Tests\Concerns\WritesRateSheets;
use Tests\TestCase;

/**
 * The import as an admin goes through it: upload, check the suggested
 * mapping, confirm it, then make a draft. The queue runs synchronously in
 * tests, so each step's job has run by the time the page reloads.
 */
class RateImportFlowTest extends TestCase
{
    use CreatesRateCards;
    use RefreshDatabase;
    use WritesRateSheets;

    private User $admin;

    private RateCard $zones;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->admin = User::factory()->admin()->create(['name' => 'Farah Aziz']);
        $this->zones = $this->zoneRates();
    }

    public function test_the_upload_page_offers_versions_with_zones_and_the_latest_imports()
    {
        $blank = RateCard::factory()->create(['name' => 'Blank draft']);
        $import = RateImport::factory()->create(['original_name' => 'east.xlsx', 'user_id' => $this->admin->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.rates.imports.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/rates/imports/Create')
                // The zone rates and the Standard rates; the blank draft has no zones.
                ->has('rateCards', 2)
                ->where('rateCards.0.id', $this->zones->id)
                ->where('currentRateCardId', $this->zones->id)
                ->where('maxKb', 5120)
                ->has('recent', 1)
                ->where('recent.0.id', $import->id)
                ->where('recent.0.original_name', 'east.xlsx')
                ->where('recent.0.status', ['value' => 'uploaded', 'label' => 'Uploaded'])
                ->where('recent.0.user', ['id' => $this->admin->id, 'name' => 'Farah Aziz'])
                ->missing('recent.0.preview'));

        $this->assertNotContains($blank->id, RateCard::query()->has('zones')->pluck('id'));
    }

    public function test_an_uploaded_file_is_read_and_its_mapping_suggested()
    {
        $import = $this->uploadAs($this->admin, $this->xlsxFile(['Rates' => self::longZoneRates()]), 'zone-rates.xlsx');

        $this->assertSame(RateImportStatus::NeedsMapping, $import->status);
        $this->assertSame($this->zones->id, $import->base_rate_card_id);
        $this->assertSame('zone-rates.xlsx', $import->original_name);
        Storage::disk('local')->assertExists((string) $import->path);

        $this->actingAs($this->admin)
            ->get(route('admin.rates.imports.show', $import))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/rates/imports/Show')
                ->where('rateImport.status.value', 'needs_mapping')
                ->where('rateImport.is_running', false)
                ->where('rateImport.layout', ['value' => 'long', 'label' => 'A row per weight band'])
                ->where('rateImport.sheet', 'Rates')
                ->where('rateImport.mapping.header_row', 1)
                ->where('rateImport.mapping.columns', ['origin' => 0, 'destination' => 1, 'weight' => 2, 'price' => 3])
                ->where('rateImport.preview.sheets', ['Rates'])
                ->where('rateImport.preview.rows.0', ['number' => 1, 'cells' => ['Origin', 'Destination', 'Max weight (kg)', 'Price (RM)']])
                ->where('rateImport.preview.rows.1.cells', ['Peninsular Malaysia', 'Peninsular Malaysia', '1', '8'])
                ->where('rateImport.preview.columns', 4)
                ->where('rateImport.base_rate_card', ['id' => $this->zones->id, 'name' => 'Zone rates'])
                ->where('rateImport.file_kept', true)
                ->has('zones', 3)
                ->where('zones.1', ['code' => 'sabah-labuan', 'name' => 'Sabah & Labuan', 'states' => ['Sabah', 'Labuan']])
                ->where('can', ['map' => true, 'chooseSheet' => false, 'createDraft' => false]));
    }

    public function test_a_confirmed_mapping_is_checked_and_the_file_becomes_a_draft()
    {
        $import = $this->uploadAs($this->admin, $this->xlsxFile(['Rates' => self::longZoneRates()]), 'zone-rates.xlsx');

        $this->actingAs($this->admin)
            ->put(route('admin.rates.imports.mapping', $import), $this->longMapping())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.rates.imports.show', $import));

        $this->assertSame(RateImportStatus::Ready, $import->refresh()->status);

        $this->actingAs($this->admin)
            ->get(route('admin.rates.imports.show', $import))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rateImport.status.value', 'ready')
                ->where('rateImport.errors', null)
                ->where('rateImport.summary.rows', 34)
                ->where('rateImport.summary.bands', 25)
                ->has('rateImport.summary.routes', 9)
                ->where('rateImport.summary.routes.1', [
                    'from' => 'peninsular-malaysia',
                    'to' => 'sabah-labuan',
                    'extraKgSen' => 500,
                    'bands' => [
                        ['maxWeightG' => 500, 'priceSen' => 900],
                        ['maxWeightG' => 1000, 'priceSen' => 1200],
                        ['maxWeightG' => 2000, 'priceSen' => 1700],
                        ['maxWeightG' => 5000, 'priceSen' => 3100],
                    ],
                ])
                ->where('can.createDraft', true));

        $response = $this->actingAs($this->admin)->post(route('admin.rates.imports.draft', $import));

        $draft = RateCard::query()->latest('id')->firstOrFail();
        $response->assertRedirect(route('admin.rates.show', $draft))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Draft created from the file. Check it, then publish it.']);

        $this->assertSame(RateCardStatus::Draft, $draft->status);
        $this->assertSame('Imported from zone-rates', $draft->name);
        $this->assertSame('Imported from zone-rates.xlsx (sheet Rates), with the zones and divisor of Zone rates.', $draft->notes);
        $this->assertSame($this->admin->id, $draft->created_by);
        $this->assertSame([], app(PublishRateCard::class)->problems($draft));
        $this->assertSame(RateImportStatus::Applied, $import->refresh()->status);
        $this->assertSame($draft->id, $import->rate_card_id);

        // A second click is told why nothing happened.
        $page = route('admin.rates.imports.show', $import);
        $this->actingAs($this->admin)->from($page)->post(route('admin.rates.imports.draft', $import))
            ->assertRedirect($page)
            ->assertSessionHasErrors(['import' => 'A draft was already made from this file.']);
        $this->assertSame(1, RateCard::query()->where('name', 'Imported from zone-rates')->count());
    }

    public function test_nothing_is_written_to_the_rate_card_tables_before_the_draft_is_made()
    {
        $counts = fn (): array => [RateCard::query()->count(), RateCardZone::query()->count(), RateCardRoute::query()->count(), RateCardBand::query()->count()];
        $before = $counts();

        $import = $this->uploadAs($this->admin, $this->xlsxFile(['Rates' => self::longZoneRates()]), 'zone-rates.xlsx');
        $this->actingAs($this->admin)->put(route('admin.rates.imports.mapping', $import), $this->longMapping());

        $this->assertSame(RateImportStatus::Ready, $import->refresh()->status);
        $this->assertSame($before, $counts());

        $this->actingAs($this->admin)->post(route('admin.rates.imports.draft', $import));

        $this->assertSame([$before[0] + 1, $before[1] + 3, $before[2] + 9, $before[3] + 25], $counts());
    }

    public function test_a_file_with_problems_lists_them_and_can_be_mapped_again()
    {
        $rows = self::longZoneRates();
        $rows[3][3] = 'twelve';

        $import = $this->uploadAs($this->admin, $this->xlsxFile(['Rates' => $rows]), 'zone-rates.xlsx');
        $this->actingAs($this->admin)->put(route('admin.rates.imports.mapping', $import), $this->longMapping());

        $this->actingAs($this->admin)
            ->get(route('admin.rates.imports.show', $import))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rateImport.status', ['value' => 'failed', 'label' => 'Has problems'])
                ->where('rateImport.errors.total', 1)
                ->where('rateImport.errors.items.0', ['row' => 4, 'column' => 'D', 'message' => '“twelve” is not a price.'])
                ->where('can', ['map' => true, 'chooseSheet' => false, 'createDraft' => false]));

        $page = route('admin.rates.imports.show', $import);
        $this->actingAs($this->admin)->from($page)->post(route('admin.rates.imports.draft', $import))
            ->assertSessionHasErrors(['import' => 'Only a file checked without problems can become a draft.']);

        // Mapped again (the price column as sen), the same file is checked again.
        $this->actingAs($this->admin)->put(route('admin.rates.imports.mapping', $import), ['price_unit' => 'sen'] + $this->longMapping())->assertSessionHasNoErrors();
        $this->assertSame(RateImportStatus::Failed, $import->refresh()->status);
        $this->assertSame('sen', $import->mapping['price_unit'] ?? null);
    }

    public function test_another_sheet_of_the_workbook_can_be_read()
    {
        $path = $this->xlsxFile([
            'Notes' => [['These are the 2027 rates.']],
            'Rates' => self::longZoneRates(),
        ]);
        $import = $this->uploadAs($this->admin, $path, 'rates-2027.xlsx');

        $this->assertSame('Notes', $import->sheet);
        $this->assertSame(['Notes', 'Rates'], $import->preview['sheets'] ?? null);

        $this->actingAs($this->admin)
            ->put(route('admin.rates.imports.sheet', $import), ['sheet' => 'Rates'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.rates.imports.show', $import));

        $import->refresh();
        $this->assertSame('Rates', $import->sheet);
        $this->assertSame(RateImportStatus::NeedsMapping, $import->status);
        $this->assertEquals(['origin' => 0, 'destination' => 1, 'weight' => 2, 'price' => 3], $import->mapping['columns'] ?? null);

        $this->actingAs($this->admin)
            ->put(route('admin.rates.imports.sheet', $import), ['sheet' => 'Prices'])
            ->assertSessionHasErrors(['sheet' => 'Choose one of the sheets in the file.']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidMappings(): array
    {
        return [
            'no layout' => [['layout' => null], 'layout'],
            'unknown layout' => [['layout' => 'wide'], 'layout'],
            'heading row beyond the sheet' => [['header_row' => 99], 'header_row'],
            'unknown unit' => [['weight_unit' => 'lb'], 'weight_unit'],
            'column missing' => [['columns.price' => null], 'columns.price'],
            'column beyond the sheet' => [['columns.price' => 4], 'columns.price'],
            'one column twice' => [['columns.price' => 2], 'columns.price'],
        ];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    #[DataProvider('invalidMappings')]
    public function test_invalid_long_mappings_are_rejected(array $changes, string $field)
    {
        $import = $this->uploadAs($this->admin, $this->xlsxFile(['Rates' => self::longZoneRates()]), 'zone-rates.xlsx');
        $mapping = $this->longMapping();

        foreach ($changes as $key => $value) {
            data_set($mapping, $key, $value);
        }

        $this->actingAs($this->admin)
            ->put(route('admin.rates.imports.mapping', $import), $mapping)
            ->assertSessionHasErrors($field);

        $this->assertSame(RateImportStatus::NeedsMapping, $import->refresh()->status);
    }

    public function test_matrix_mappings_name_each_route_once_and_routes_of_the_base_card_only()
    {
        $import = $this->uploadAs($this->admin, $this->xlsxFile(['Matrix' => [
            ['Weight (kg)', 'Peninsular Malaysia → Sarawak', 'Sarawak → Sarawak'],
            [1, 11.5, 9],
            ['Each additional kg', 4.5, 2.5],
        ]]), 'matrix.xlsx');

        $mapping = [
            'layout' => 'matrix',
            'header_row' => 1,
            'weight_unit' => 'kg',
            'price_unit' => 'rm',
            'weight_column' => 0,
            'extra_row' => 3,
            'routes' => [
                ['column' => 1, 'origin' => 'peninsular-malaysia', 'destination' => 'sarawak'],
                ['column' => 2, 'origin' => 'peninsular-malaysia', 'destination' => 'sarawak'],
            ],
        ];

        $this->actingAs($this->admin)->put(route('admin.rates.imports.mapping', $import), $mapping)
            ->assertSessionHasErrors(['routes.1.origin' => 'Column B is already Peninsular Malaysia → Sarawak.']);

        data_set($mapping, 'routes.1.origin', 'singapore');
        $this->actingAs($this->admin)->put(route('admin.rates.imports.mapping', $import), $mapping)
            ->assertSessionHasErrors(['routes.1.origin' => 'Choose a route from the list.']);

        data_set($mapping, 'routes', [['column' => 1, 'origin' => null, 'destination' => null]]);
        $this->actingAs($this->admin)->put(route('admin.rates.imports.mapping', $import), $mapping)
            ->assertSessionHasErrors(['routes' => 'Choose the route of at least one column.']);

        data_set($mapping, 'routes', [['column' => 0, 'origin' => 'sarawak', 'destination' => 'sarawak']]);
        $this->actingAs($this->admin)->put(route('admin.rates.imports.mapping', $import), $mapping)
            ->assertSessionHasErrors(['routes.0.origin' => 'This is the weight column.']);

        data_set($mapping, 'routes', [['column' => 2, 'origin' => 'sarawak', 'destination' => 'sarawak']]);
        data_set($mapping, 'extra_row', 1);
        $this->actingAs($this->admin)->put(route('admin.rates.imports.mapping', $import), $mapping)
            ->assertSessionHasErrors(['extra_row' => 'Choose a row under the headings.']);

        $this->assertSame(RateImportStatus::NeedsMapping, $import->refresh()->status);
    }

    public function test_an_import_made_into_a_draft_cannot_be_mapped_or_read_again()
    {
        $import = $this->uploadAs($this->admin, $this->xlsxFile(['Rates' => self::longZoneRates(), 'Other' => [['x']]]), 'zone-rates.xlsx');
        $this->actingAs($this->admin)->put(route('admin.rates.imports.mapping', $import), $this->longMapping());
        $this->actingAs($this->admin)->post(route('admin.rates.imports.draft', $import));

        $this->actingAs($this->admin)->put(route('admin.rates.imports.mapping', $import), $this->longMapping())
            ->assertSessionHasErrors(['import' => 'A draft was already made from this file.']);
        $this->actingAs($this->admin)->put(route('admin.rates.imports.sheet', $import), ['sheet' => 'Other'])
            ->assertSessionHasErrors(['import' => 'A draft was already made from this file.']);

        $this->actingAs($this->admin)
            ->get(route('admin.rates.imports.show', $import))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rateImport.status.value', 'applied')
                ->where('rateImport.rate_card.id', $import->refresh()->rate_card_id)
                ->where('rateImport.draft_deleted', false)
                // Nothing on the page shows the rows any more.
                ->missing('rateImport.preview')
                ->where('can', ['map' => false, 'chooseSheet' => false, 'createDraft' => false]));
    }

    public function test_a_draft_deleted_after_it_was_made_can_be_made_again()
    {
        $import = $this->uploadAs($this->admin, $this->xlsxFile(['Rates' => self::longZoneRates()]), 'zone-rates.xlsx');
        $this->actingAs($this->admin)->put(route('admin.rates.imports.mapping', $import), $this->longMapping());
        $this->actingAs($this->admin)->post(route('admin.rates.imports.draft', $import));
        $first = RateCard::query()->findOrFail($import->refresh()->rate_card_id);

        $this->actingAs($this->admin)->delete(route('admin.rates.destroy', $first))->assertSessionHasNoErrors();

        $this->assertSame(RateImportStatus::Applied, $import->refresh()->status);
        $this->assertNull($import->rate_card_id);

        $this->actingAs($this->admin)
            ->get(route('admin.rates.imports.show', $import))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rateImport.status.value', 'applied')
                ->where('rateImport.rate_card', null)
                ->where('rateImport.draft_deleted', true)
                ->where('can.createDraft', true));

        $this->actingAs($this->admin)->post(route('admin.rates.imports.draft', $import))->assertSessionHasNoErrors();

        $second = RateCard::query()->findOrFail($import->refresh()->rate_card_id);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame([], app(PublishRateCard::class)->problems($second));
        $this->assertSame(RateImportStatus::Applied, $import->status);
    }

    public function test_no_draft_is_offered_once_the_base_rates_are_deleted()
    {
        $base = RateCard::factory()->withRates(self::zones(), [])->create(['name' => 'Draft zones']);
        $import = RateImport::factory()->status(RateImportStatus::Ready)->create([
            'base_rate_card_id' => $base->id,
            'summary' => ['rows' => 34, 'bands' => 25, 'routes' => self::routes()],
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.rates.imports.show', $import))
            ->assertInertia(fn (Assert $page) => $page->where('can.createDraft', true));

        $base->delete();

        $this->actingAs($this->admin)
            ->get(route('admin.rates.imports.show', $import))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rateImport.base_rate_card', null)
                ->where('zones', [])
                ->where('can.createDraft', false));
    }

    public function test_uploads_sheets_and_mappings_are_limited_to_10_a_minute()
    {
        $import = RateImport::factory()->status(RateImportStatus::Ready)->create();

        foreach (range(1, 4) as $i) {
            $this->actingAs($this->admin)->post(route('admin.rates.imports.store'), [])->assertSessionHasErrors('file');
            $this->actingAs($this->admin)->put(route('admin.rates.imports.sheet', $import), [])->assertSessionHasErrors('sheet');
        }

        $this->actingAs($this->admin)->put(route('admin.rates.imports.mapping', $import), [])->assertSessionHasErrors('layout');
        $this->actingAs($this->admin)->put(route('admin.rates.imports.mapping', $import), [])->assertSessionHasErrors('layout');

        $this->actingAs($this->admin)->post(route('admin.rates.imports.store'), [])->assertTooManyRequests();
        $this->actingAs($this->admin)->put(route('admin.rates.imports.mapping', $import), [])->assertTooManyRequests();

        // Another admin has a limit of their own, and pages still open.
        $this->actingAs(User::factory()->admin()->create())->post(route('admin.rates.imports.store'), [])->assertSessionHasErrors('file');
        $this->actingAs($this->admin)->get(route('admin.rates.imports.show', $import))->assertOk();
    }

    /**
     * The suggested mapping of longZoneRates(), as the page sends it back.
     *
     * @return array<string, mixed>
     */
    private function longMapping(): array
    {
        return [
            'layout' => 'long',
            'header_row' => 1,
            'weight_unit' => 'kg',
            'price_unit' => 'rm',
            'columns' => ['origin' => 0, 'destination' => 1, 'weight' => 2, 'price' => 3],
        ];
    }
}
