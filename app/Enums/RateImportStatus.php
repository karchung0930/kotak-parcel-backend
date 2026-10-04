<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a rate card import stands. The two queued jobs move it along:
 * App\Jobs\ParseRateImport reads the file (uploaded → parsing →
 * needs_mapping), App\Jobs\ValidateRateImport checks every row with the
 * confirmed mapping (validating → ready or failed). Creating the draft
 * makes it applied.
 */
enum RateImportStatus: string
{
    use HasOptions;

    /** Stored, waiting for the queue to read it. */
    case Uploaded = 'uploaded';

    /** Being read: sheets, headings and layout. */
    case Parsing = 'parsing';

    /** Read; the admin checks how the columns map. */
    case NeedsMapping = 'needs_mapping';

    /** Every row being checked with the confirmed mapping. */
    case Validating = 'validating';

    /** Checked without problems; a draft can be made from it. */
    case Ready = 'ready';

    /** The file could not be read, or its rows have problems. */
    case Failed = 'failed';

    /** A draft rate card was made from it. */
    case Applied = 'applied';

    /**
     * Get the human readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Uploaded',
            self::Parsing => 'Reading',
            self::NeedsMapping => 'Check columns',
            self::Validating => 'Checking',
            self::Ready => 'Ready',
            self::Failed => 'Has problems',
            self::Applied => 'Draft created',
        };
    }

    /**
     * Determine if a queued job is working on the import, so its page keeps
     * asking for news.
     */
    public function isRunning(): bool
    {
        return in_array($this, [self::Uploaded, self::Parsing, self::Validating], true);
    }
}
