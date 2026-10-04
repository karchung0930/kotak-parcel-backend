<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How a spreadsheet of prices is laid out.
 */
enum RateImportLayout: string
{
    use HasOptions;

    /** A row per band: origin, destination, max weight and price columns. */
    case Long = 'long';

    /** Weights down the first column and a column per route. */
    case Matrix = 'matrix';

    /**
     * Get the human readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Long => 'A row per weight band',
            self::Matrix => 'Weights by route',
        };
    }
}
