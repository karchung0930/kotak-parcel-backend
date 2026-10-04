<?php

namespace Tests\Feature\RateImports;

use App\Actions\RateImports\ChooseRateImportSheet;
use App\Actions\RateImports\ConfirmRateImportMapping;
use App\Enums\RateImportLayout;
use App\Enums\RateImportStatus;
use App\Models\RateImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesRateCards;
use Tests\TestCase;

/**
 * What an admin is told when a page gone stale asks for a step the import
 * can no longer take. The refusal comes under "import", which the page
 * shows whatever state it reloads in, as the form may be gone by then.
 */
class RateImportRefusalsTest extends TestCase
{
    use CreatesRateCards;
    use RefreshDatabase;

    private const MAPPING = [
        'header_row' => 1,
        'columns' => ['origin' => 0, 'destination' => 1, 'weight' => 2, 'price' => 3],
        'weight_unit' => 'kg',
        'price_unit' => 'rm',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
        $this->zoneRates();
    }

    /**
     * @return array<string, array{RateImportStatus, bool, string}>
     */
    public static function mappingRefusals(): array
    {
        return [
            'waiting to be read' => [RateImportStatus::Uploaded, false, 'The file is still being read. Wait a moment and try again.'],
            'being read' => [RateImportStatus::Parsing, false, 'The file is still being read. Wait a moment and try again.'],
            'being checked' => [RateImportStatus::Validating, true, 'Every row is being checked. Wait a moment and try again.'],
            'could not be read' => [RateImportStatus::Failed, false, 'This file could not be read. Upload it again.'],
            'made into a draft' => [RateImportStatus::Applied, true, 'A draft was already made from this file.'],
        ];
    }

    #[DataProvider('mappingRefusals')]
    public function test_the_columns_cannot_be_confirmed_once_the_import_has_moved_on(RateImportStatus $status, bool $read, string $message)
    {
        $import = $this->import($status, $read);

        $this->assertRefused(['import' => [$message]], fn () => app(ConfirmRateImportMapping::class)->handle($import, RateImportLayout::Long, self::MAPPING));
        $this->assertSame($status, $import->refresh()->status);
    }

    public function test_the_columns_cannot_be_confirmed_once_the_file_is_deleted()
    {
        $import = $this->import(RateImportStatus::NeedsMapping, true, keepFile: false);

        $this->assertRefused(
            ['import' => ['The file is no longer kept (files are deleted after 7 days). Upload it again.']],
            fn () => app(ConfirmRateImportMapping::class)->handle($import, RateImportLayout::Long, self::MAPPING),
        );
        $this->assertSame(RateImportStatus::NeedsMapping, $import->refresh()->status);
    }

    /**
     * @return array<string, array{RateImportStatus, list<string>, string}>
     */
    public static function sheetRefusals(): array
    {
        return [
            'waiting to be read' => [RateImportStatus::Uploaded, ['Rates', 'Other'], 'The file is still being read. Wait a moment and try again.'],
            'being read' => [RateImportStatus::Parsing, ['Rates', 'Other'], 'The file is still being read. Wait a moment and try again.'],
            'being checked' => [RateImportStatus::Validating, ['Rates', 'Other'], 'Every row is being checked. Wait a moment and try again.'],
            'made into a draft' => [RateImportStatus::Applied, ['Rates', 'Other'], 'A draft was already made from this file.'],
            'only one sheet' => [RateImportStatus::NeedsMapping, ['Rates'], 'The sheet cannot be changed now.'],
        ];
    }

    /**
     * @param  list<string>  $sheets
     */
    #[DataProvider('sheetRefusals')]
    public function test_the_sheet_cannot_be_changed_once_the_import_has_moved_on(RateImportStatus $status, array $sheets, string $message)
    {
        $import = $this->import($status, true, $sheets);

        $this->assertRefused(['import' => [$message]], fn () => app(ChooseRateImportSheet::class)->handle($import, 'Other'));
        $this->assertSame($status, $import->refresh()->status);
        $this->assertSame('Rates', $import->sheet);
    }

    public function test_the_sheet_cannot_be_changed_once_the_file_is_deleted()
    {
        $import = $this->import(RateImportStatus::Failed, true, keepFile: false);

        $this->assertRefused(
            ['import' => ['The file is no longer kept (files are deleted after 7 days). Upload it again.']],
            fn () => app(ChooseRateImportSheet::class)->handle($import, 'Other'),
        );
    }

    public function test_the_page_is_sent_back_with_the_refusal_under_import()
    {
        $import = $this->import(RateImportStatus::Validating, true);
        $admin = User::factory()->admin()->create();
        $page = route('admin.rates.imports.show', $import);

        $this->actingAs($admin)->from($page)
            ->put(route('admin.rates.imports.mapping', $import), ['layout' => 'long'] + self::MAPPING)
            ->assertRedirect($page)
            ->assertSessionHasErrors(['import' => 'Every row is being checked. Wait a moment and try again.']);
    }

    /**
     * An import in the given state, read (with a layout, mapping and the
     * first rows) or not, with its file on the disk unless it was deleted.
     *
     * @param  list<string>  $sheets
     */
    private function import(RateImportStatus $status, bool $read, array $sheets = ['Rates', 'Other'], bool $keepFile = true): RateImport
    {
        $import = RateImport::factory()->status($status)->create($read ? [
            'sheet' => 'Rates',
            'layout' => RateImportLayout::Long,
            'mapping' => self::MAPPING,
            'preview' => ['sheets' => $sheets, 'rows' => [], 'columns' => 4, 'last_row' => 35],
        ] : ['sheet' => 'Rates']);

        if ($keepFile) {
            Storage::disk('local')->put((string) $import->path, 'rates');
        }

        return $import;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function assertRefused(array $errors, callable $step): void
    {
        try {
            $step();
            $this->fail('The step should have been refused.');
        } catch (ValidationException $e) {
            $this->assertSame($errors, $e->errors());
        }

        Queue::assertNothingPushed();
    }
}
