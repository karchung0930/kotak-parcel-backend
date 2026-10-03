<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a rate card stands, as admins see it. Worked out from the stored
 * status and the effective date (see RateCard::phase()), never stored.
 */
enum RateCardPhase: string
{
    use HasOptions;

    /** Being edited; prices nothing. */
    case Draft = 'draft';

    /** Published, taking effect at a future moment. */
    case Scheduled = 'scheduled';

    /** The published card that prices new orders now. */
    case Current = 'current';

    /** Published, replaced by a later card. */
    case Past = 'past';

    /**
     * Get the human readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Current => 'Current',
            self::Past => 'Past',
        };
    }
}
