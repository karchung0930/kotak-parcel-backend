<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum MalaysianState: string
{
    use HasOptions;

    case Johor = 'Johor';
    case Kedah = 'Kedah';
    case Kelantan = 'Kelantan';
    case Melaka = 'Melaka';
    case NegeriSembilan = 'Negeri Sembilan';
    case Pahang = 'Pahang';
    case Perak = 'Perak';
    case Perlis = 'Perlis';
    case PulauPinang = 'Pulau Pinang';
    case Sabah = 'Sabah';
    case Sarawak = 'Sarawak';
    case Selangor = 'Selangor';
    case Terengganu = 'Terengganu';
    case KualaLumpur = 'Kuala Lumpur';
    case Labuan = 'Labuan';
    case Putrajaya = 'Putrajaya';

    /**
     * Get the human readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::KualaLumpur => 'W.P. Kuala Lumpur',
            self::Labuan => 'W.P. Labuan',
            self::Putrajaya => 'W.P. Putrajaya',
            default => $this->value,
        };
    }
}
