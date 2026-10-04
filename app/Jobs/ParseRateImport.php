<?php

namespace App\Jobs;

use App\Actions\RateImports\ReadRateImport;
use App\Models\RateImport;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

/**
 * Reads an uploaded rates file on the queue: its sheets, headings and
 * layout (App\Actions\RateImports\ReadRateImport). Queued once the upload
 * has committed, so the worker always finds the import.
 */
#[Tries(2)]
#[Backoff(10)]
#[Timeout(60)]
#[DeleteWhenMissingModels]
class ParseRateImport implements ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public RateImport $import,
    ) {}

    /**
     * Read the file.
     */
    public function handle(ReadRateImport $read): void
    {
        $read->handle($this->import);
    }

    /**
     * Tell the admin when reading the file kept failing.
     */
    public function failed(?Throwable $exception): void
    {
        app(ReadRateImport::class)->giveUp($this->import);
    }
}
