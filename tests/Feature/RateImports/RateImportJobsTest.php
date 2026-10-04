<?php

namespace Tests\Feature\RateImports;

use App\Enums\RateImportStatus;
use App\Jobs\ParseRateImport;
use App\Jobs\ValidateRateImport;
use App\Models\RateImport;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionClass;
use RuntimeException;
use Tests\Concerns\CreatesRateCards;
use Tests\Concerns\WritesRateSheets;
use Tests\TestCase;

/**
 * How the two queued jobs move an import along, as the worker runs them:
 * uploaded → parsing → needs_mapping, then validating → ready or failed.
 */
class RateImportJobsTest extends TestCase
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
        $this->zoneRates();
    }

    public function test_an_upload_waits_for_the_worker_and_its_page_keeps_asking()
    {
        $import = $this->uploadAs($this->admin, $this->xlsxFile(['Rates' => self::longZoneRates()]), 'rates.xlsx');

        $this->assertSame(RateImportStatus::Uploaded, $import->status);
        Queue::assertPushed(ParseRateImport::class, fn (ParseRateImport $job) => $job->import->is($import));

        $this->actingAs($this->admin)
            ->get(route('admin.rates.imports.show', $import))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rateImport.status.value', 'uploaded')
                ->where('rateImport.is_running', true)
                ->missing('rateImport.preview')
                ->where('can.map', false));

        $this->runJob(new ParseRateImport($import));

        $this->assertSame(RateImportStatus::NeedsMapping, $import->refresh()->status);
    }

    public function test_a_second_copy_of_a_job_changes_nothing()
    {
        $import = $this->uploadAs($this->admin, $this->xlsxFile(['Rates' => self::longZoneRates()]), 'rates.xlsx');
        $this->runJob(new ParseRateImport($import));
        $import->refresh();
        $import->forceFill(['mapping' => ['header_row' => 1, 'weight_unit' => 'g', 'price_unit' => 'sen', 'columns' => ['origin' => 0, 'destination' => 1, 'weight' => 2, 'price' => 3]]])->save();

        $this->runJob(new ParseRateImport($import));

        $this->assertSame(RateImportStatus::NeedsMapping, $import->refresh()->status);
        $this->assertSame('g', $import->mapping['weight_unit'] ?? null);

        $this->runJob(new ValidateRateImport($import));

        $this->assertSame(RateImportStatus::NeedsMapping, $import->refresh()->status);
        $this->assertNull($import->summary);
    }

    public function test_a_confirmed_mapping_waits_for_the_worker_then_is_ready()
    {
        $import = $this->uploadAs($this->admin, $this->xlsxFile(['Rates' => self::longZoneRates()]), 'rates.xlsx');
        $this->runJob(new ParseRateImport($import));

        $this->actingAs($this->admin)->put(route('admin.rates.imports.mapping', $import), [
            'layout' => 'long',
            'header_row' => 1,
            'weight_unit' => 'kg',
            'price_unit' => 'rm',
            'columns' => ['origin' => 0, 'destination' => 1, 'weight' => 2, 'price' => 3],
        ])->assertSessionHasNoErrors();

        $this->assertSame(RateImportStatus::Validating, $import->refresh()->status);
        Queue::assertPushed(ValidateRateImport::class, fn (ValidateRateImport $job) => $job->import->is($import));

        $this->runJob(new ValidateRateImport($import));

        $this->assertSame(RateImportStatus::Ready, $import->refresh()->status);
    }

    public function test_a_job_that_keeps_failing_marks_the_import_failed()
    {
        $import = RateImport::factory()->status(RateImportStatus::Parsing)->create();

        (new ParseRateImport($import))->failed(new RuntimeException('Out of memory'));

        $this->assertSame(RateImportStatus::Failed, $import->refresh()->status);
        $this->assertSame('Reading the file took too long or kept failing. Upload it again, or split it into smaller files.', $import->errors['items'][0]['message'] ?? null);

        $import = RateImport::factory()->status(RateImportStatus::Validating)->create();

        (new ValidateRateImport($import))->failed(null);

        $this->assertSame(RateImportStatus::Failed, $import->refresh()->status);
        $this->assertSame('Checking the file took too long or kept failing. Check it again, or split it into smaller files.', $import->errors['items'][0]['message'] ?? null);

        // An import that has moved on is left as it is.
        $import = RateImport::factory()->status(RateImportStatus::Ready)->create();

        (new ParseRateImport($import))->failed(null);
        (new ValidateRateImport($import))->failed(null);

        $this->assertSame(RateImportStatus::Ready, $import->refresh()->status);
    }

    public function test_the_jobs_are_queued_once_the_upload_has_committed()
    {
        $this->assertInstanceOf(ShouldQueueAfterCommit::class, new ParseRateImport(new RateImport));
        $this->assertInstanceOf(ShouldQueueAfterCommit::class, new ValidateRateImport(new RateImport));
    }

    public function test_a_job_that_runs_too_long_is_stopped_before_the_queue_hands_it_out_again()
    {
        foreach ([ParseRateImport::class, ValidateRateImport::class] as $job) {
            $timeout = (new ReflectionClass($job))->getAttributes(Timeout::class)[0]->newInstance()->timeout;
            $tries = (new ReflectionClass($job))->getAttributes(Tries::class)[0]->newInstance()->tries;

            // Stopped at its timeout, the job is tried again; after the last try, failed() marks the import.
            $this->assertSame(60, $timeout, $job);
            $this->assertSame(2, $tries, $job);
            // A job still running when retry_after passes would be run twice at once.
            $this->assertLessThan(config()->integer('queue.connections.database.retry_after'), $timeout, $job);
        }
    }

    /**
     * Run a queued job as the worker would.
     */
    private function runJob(ParseRateImport|ValidateRateImport $job): void
    {
        app()->call([$job, 'handle']);
    }
}
