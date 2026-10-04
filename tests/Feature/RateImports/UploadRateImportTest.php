<?php

namespace Tests\Feature\RateImports;

use App\Enums\RateImportStatus;
use App\Enums\Role;
use App\Jobs\ParseRateImport;
use App\Models\RateCard;
use App\Models\RateImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesRateCards;
use Tests\Concerns\WritesRateSheets;
use Tests\TestCase;
use ZipArchive;

/**
 * Uploading a file: what is accepted, where it is kept, which zones its
 * prices use, and who may import at all.
 */
class UploadRateImportTest extends TestCase
{
    use CreatesRateCards;
    use RefreshDatabase;
    use WritesRateSheets;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_an_excel_or_csv_file_is_kept_on_the_private_disk_under_a_random_name()
    {
        $current = $this->zoneRates();

        $this->actingAs($this->admin)
            ->post(route('admin.rates.imports.store'), ['file' => $this->upload($this->xlsxFile(['Rates' => self::longZoneRates()]), 'Rates 2027.xlsx')])
            ->assertRedirect(route('admin.rates.imports.show', RateImport::query()->latest('id')->firstOrFail()));

        $import = RateImport::query()->sole();
        $this->assertSame('Rates 2027.xlsx', $import->original_name);
        $this->assertSame($this->admin->id, $import->user_id);
        // The current rates' zones, unless others are chosen.
        $this->assertSame($current->id, $import->base_rate_card_id);
        $this->assertSame(RateImportStatus::Uploaded, $import->status);
        $this->assertMatchesRegularExpression('#^rate-imports/[0-9a-f-]{36}\.xlsx$#', (string) $import->path);
        Storage::disk('local')->assertExists((string) $import->path);

        $standard = RateCard::query()->orderBy('id')->firstOrFail();
        $this->actingAs($this->admin)
            ->post(route('admin.rates.imports.store'), [
                'file' => $this->upload($this->csvFile([['Origin', 'Destination', 'Weight', 'Price']]), 'rates.csv'),
                'base_rate_card_id' => $standard->id,
            ])
            ->assertSessionHasNoErrors();

        $csv = RateImport::query()->latest('id')->firstOrFail();
        $this->assertSame($standard->id, $csv->base_rate_card_id);
        $this->assertStringEndsWith('.csv', (string) $csv->path);
        Queue::assertPushed(ParseRateImport::class, 2);
    }

    /**
     * @return array<string, array{callable(self): UploadedFile|null, string}>
     */
    public static function invalidFiles(): array
    {
        return [
            'no file' => [fn () => null, 'Choose an Excel (.xlsx) or CSV file.'],
            'an old .xls file' => [fn () => UploadedFile::fake()->create('rates.xls', 10, 'application/vnd.ms-excel'), 'Choose an Excel (.xlsx) or CSV file. Older .xls files need saving as .xlsx first.'],
            'a PDF' => [fn () => UploadedFile::fake()->create('rates.pdf', 10, 'application/pdf'), 'Choose an Excel (.xlsx) or CSV file. Older .xls files need saving as .xlsx first.'],
            'over 5 MB' => [fn () => UploadedFile::fake()->create('rates.xlsx', 5121, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'), 'The file is over 5 MB. Remove what is not prices, or split it.'],
            // Real files, so their type is detected from what is in them.
            'random bytes named .xlsx' => [fn (self $test) => $test->fileOf(random_bytes(10240), 'rates.xlsx'), 'This file is not an Excel workbook. Save it again as .xlsx and upload it.'],
            'a picture named .xlsx' => [fn (self $test) => $test->fileOf((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='), 'rates.xlsx'), 'This file is not an Excel workbook. Save it again as .xlsx and upload it.'],
            'a zip archive of other files named .xlsx' => [fn (self $test) => $test->zipOf(['notes.txt' => 'Rates for 2027']), 'This file is not an Excel workbook. Save it again as .xlsx and upload it.'],
            'a workbook named .csv' => [fn (self $test) => $test->upload($test->xlsxFile(['Rates' => [['a']]]), 'rates.csv'), 'This file is not a CSV text file. Save it again as .csv and upload it.'],
        ];
    }

    /**
     * @param  callable(self): (UploadedFile|null)  $file
     */
    #[DataProvider('invalidFiles')]
    public function test_only_excel_and_csv_files_up_to_5_mb_are_accepted(callable $file, string $message)
    {
        // On Windows, a file an earlier test stored can stay listed for a
        // moment after the disk is emptied; only files left by this upload count.
        $before = Storage::disk('local')->allFiles();

        $this->actingAs($this->admin)
            ->post(route('admin.rates.imports.store'), array_filter(['file' => $file($this)]))
            ->assertSessionHasErrors(['file' => $message]);

        $this->assertSame(0, RateImport::query()->count());
        $this->assertSame([], array_values(array_diff(Storage::disk('local')->allFiles(), $before)));
        Queue::assertNothingPushed();
    }

    public function test_a_workbook_that_swells_when_unpacked_is_refused()
    {
        $workbook = ['[Content_Types].xml' => '<Types/>', 'xl/workbook.xml' => '<workbook/>'];

        // One part of 2 MB of the same character, packed into a few KB.
        $swelling = $this->zipOf($workbook + ['xl/worksheets/sheet1.xml' => str_repeat('0', 2 * 1024 * 1024)]);

        // 60 parts just under 1 MB each: none swells much for its size, but 60 MB in all.
        $part = $this->sheetPath('xml');
        file_put_contents($part, str_repeat('0', 1_000_000));
        $large = $this->sheetPath('xlsx');
        $zip = new ZipArchive;
        $zip->open($large, ZipArchive::CREATE);

        foreach ($workbook as $name => $content) {
            $zip->addFromString($name, $content);
        }

        foreach (range(1, 60) as $i) {
            $zip->addFile($part, "xl/media/part{$i}.xml");
        }

        $zip->close();

        foreach ([$swelling, $this->upload($large, 'rates.xlsx')] as $file) {
            $this->actingAs($this->admin)
                ->post(route('admin.rates.imports.store'), ['file' => $file])
                ->assertSessionHasErrors(['file' => 'This workbook is far larger once opened than a sheet of prices. Keep only the sheet with the prices, or save it as .csv.']);
        }

        $this->assertSame(0, RateImport::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_the_zones_must_come_from_rates_that_have_zones()
    {
        $blank = RateCard::factory()->create();
        $file = fn () => $this->upload($this->xlsxFile(['Rates' => [['a']]]), 'rates.xlsx');

        $this->actingAs($this->admin)
            ->post(route('admin.rates.imports.store'), ['file' => $file(), 'base_rate_card_id' => $blank->id])
            ->assertSessionHasErrors(['base_rate_card_id' => 'These rates have no zones yet. Choose rates with zones.']);

        $this->actingAs($this->admin)
            ->post(route('admin.rates.imports.store'), ['file' => $file(), 'base_rate_card_id' => 999])
            ->assertSessionHasErrors(['base_rate_card_id' => 'Those rates no longer exist. Choose others.']);

        $this->assertSame(0, RateImport::query()->count());
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function otherRoles(): array
    {
        return [
            'staff' => [Role::Staff],
            'driver' => [Role::Driver],
            'customer' => [Role::Customer],
        ];
    }

    #[DataProvider('otherRoles')]
    public function test_only_admins_import_rates(Role $role)
    {
        $user = User::factory()->create(['role' => $role]);
        $import = RateImport::factory()->status(RateImportStatus::Ready)->create();

        $this->actingAs($user)->get(route('admin.rates.imports.create'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.rates.imports.store'), ['file' => $this->upload($this->csvFile([['a']]), 'rates.csv')])->assertForbidden();
        $this->actingAs($user)->get(route('admin.rates.imports.show', $import))->assertForbidden();
        $this->actingAs($user)->put(route('admin.rates.imports.sheet', $import), ['sheet' => 'Rates'])->assertForbidden();
        $this->actingAs($user)->put(route('admin.rates.imports.mapping', $import), ['layout' => 'long'])->assertForbidden();
        $this->actingAs($user)->post(route('admin.rates.imports.draft', $import))->assertForbidden();

        $this->assertSame(1, RateImport::query()->count());
        $this->assertSame(RateImportStatus::Ready, $import->refresh()->status);
    }

    public function test_guests_are_sent_to_log_in()
    {
        $this->get(route('admin.rates.imports.create'))->assertRedirect(route('login'));
    }

    /**
     * Get the given bytes as an uploaded file.
     */
    private function fileOf(string $bytes, string $name): UploadedFile
    {
        $path = $this->sheetPath(pathinfo($name, PATHINFO_EXTENSION));
        file_put_contents($path, $bytes);

        return $this->upload($path, $name);
    }

    /**
     * Get a zip archive of the given files (name => content) as an uploaded .xlsx file.
     *
     * @param  array<string, string>  $files
     */
    private function zipOf(array $files): UploadedFile
    {
        $path = $this->sheetPath('xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);

        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();

        return $this->upload($path, 'rates.xlsx');
    }
}
