<?php

namespace App\Jobs;

use App\Actions\RateImports\CheckRateImport;
use App\Models\RateImport;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

/**
 * Checks every row of a rates file with the mapping the admin confirmed
 * (App\Actions\RateImports\CheckRateImport), on the queue.
 */
#[Tries(2)]
#[Backoff(10)]
#[Timeout(60)]
#[DeleteWhenMissingModels]
class ValidateRateImport implements ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public RateImport $import,
    ) {}

    /**
     * Check the file.
     */
    public function handle(CheckRateImport $check): void
    {
        $check->handle($this->import);
    }

    /**
     * Tell the admin when checking the file kept failing.
     */
    public function failed(?Throwable $exception): void
    {
        app(CheckRateImport::class)->giveUp($this->import);
    }
}
