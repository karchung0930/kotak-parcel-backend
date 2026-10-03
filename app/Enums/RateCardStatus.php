<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The stored status of a rate card. Admins see RateCardPhase instead, which
 * also says whether a published card is scheduled, current or past.
 */
enum RateCardStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Published = 'published';

    /**
     * Get the human readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
        };
    }
}
