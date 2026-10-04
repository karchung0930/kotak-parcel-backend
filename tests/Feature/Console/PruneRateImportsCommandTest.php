<?php

namespace Tests\Feature\Console;

use App\Enums\RateImportStatus;
use App\Models\RateImport;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneRateImportsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->freezeSecond();
    }

    public function test_files_of_imports_older_than_a_week_are_deleted()
    {
        $old = $this->importWithFile(now()->subDays(7)->subMinute(), RateImportStatus::Ready);
        $recent = $this->importWithFile(now()->subDays(7)->addMinute(), RateImportStatus::NeedsMapping);
        $cleaned = RateImport::factory()->create(['path' => null, 'created_at' => now()->subDays(30)]);

        $this->artisan('rates:prune-imports')
            ->expectsOutput('Deleted 1 rate import file(s).')
            ->assertSuccessful();

        Storage::disk('local')->assertMissing((string) $old->path);
        Storage::disk('local')->assertExists((string) $recent->path);

        // The import stays, with what was read from it.
        $this->assertNull($old->refresh()->path);
        $this->assertSame(RateImportStatus::Ready, $old->status);
        $this->assertNotNull($recent->refresh()->path);
        $this->assertModelExists($cleaned);
    }

    public function test_the_file_s_text_goes_with_it_but_the_checked_prices_stay()
    {
        $preview = [
            'sheets' => ['Rates', 'Customers'],
            'rows' => [['number' => 1, 'cells' => ['Name', 'Phone']], ['number' => 2, 'cells' => ['Aisyah', '012-345 6789']]],
            'columns' => 2,
            'last_row' => 2,
            'cut_short' => false,
        ];
        $summary = ['rows' => 34, 'bands' => 25, 'routes' => []];
        $ready = $this->importWithFile(now()->subDays(8), RateImportStatus::Ready, ['preview' => $preview, 'summary' => $summary]);
        $failed = $this->importWithFile(now()->subDays(8), RateImportStatus::Failed, [
            'preview' => $preview,
            'errors' => ['total' => 1, 'items' => [['row' => 2, 'column' => 'A', 'message' => '“Aisyah” is not one of the zones (Malaysia).']]],
        ]);

        $this->artisan('rates:prune-imports')->assertSuccessful();

        $ready->refresh();
        $this->assertEquals(['sheets' => ['Rates', 'Customers'], 'rows' => [], 'columns' => 2, 'last_row' => 2, 'cut_short' => false], $ready->preview);
        $this->assertEquals($summary, $ready->summary);
        $this->assertNull($ready->errors);

        $failed->refresh();
        $this->assertSame([], $failed->preview['rows'] ?? null);
        $this->assertEquals(
            ['total' => 1, 'items' => [['row' => null, 'column' => null, 'message' => 'The file was deleted after 7 days, with the problems found in it. Upload it again to check it.']]],
            $failed->errors,
        );
    }

    public function test_old_files_no_import_points_at_are_deleted_too()
    {
        $disk = Storage::disk('local');
        $disk->put('rate-imports/left-over.xlsx', 'x');
        touch($disk->path('rate-imports/left-over.xlsx'), now()->subDays(8)->getTimestamp());
        $disk->put('rate-imports/just-uploaded.xlsx', 'x');
        $disk->put('pod/1/photo.jpg', 'x');
        touch($disk->path('pod/1/photo.jpg'), now()->subDays(30)->getTimestamp());

        $this->artisan('rates:prune-imports')->expectsOutput('Deleted 1 rate import file(s).');

        $disk->assertMissing('rate-imports/left-over.xlsx');
        $disk->assertExists('rate-imports/just-uploaded.xlsx');
        // Only uploaded rates go: delivery photos are kept.
        $disk->assertExists('pod/1/photo.jpg');
    }

    public function test_it_runs_every_night_at_3am_malaysia_time()
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'rates:prune-imports'));

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame('0 3 * * *', $event->expression);
        $this->assertSame('Asia/Kuala_Lumpur', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function importWithFile(CarbonInterface $createdAt, RateImportStatus $status, array $attributes = []): RateImport
    {
        $import = RateImport::factory()->status($status)->create(['created_at' => $createdAt, ...$attributes]);
        Storage::disk('local')->put((string) $import->path, 'rates');

        return $import;
    }
}
