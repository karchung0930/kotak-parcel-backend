<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Which way a driver moves a stop on My jobs.
 */
enum MoveDirection: string
{
    use HasOptions;

    case Up = 'up';
    case Down = 'down';

    /**
     * Get the human readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Up => 'Move up',
            self::Down => 'Move down',
        };
    }

    /**
     * Get how many places the stop moves in the list: up is towards the start.
     */
    public function offset(): int
    {
        return match ($this) {
            self::Up => -1,
            self::Down => 1,
        };
    }
}
