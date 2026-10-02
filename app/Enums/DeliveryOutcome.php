<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum DeliveryOutcome: string
{
    use HasOptions;

    case Delivered = 'delivered';
    case Failed = 'failed';

    /**
     * Get the human readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Delivered => 'Delivered',
            self::Failed => 'Failed',
        };
    }
}
