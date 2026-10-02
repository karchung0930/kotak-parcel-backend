<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum DeliveryFailureReason: string
{
    use HasOptions;

    case RecipientUnavailable = 'recipient_unavailable';
    case AddressNotFound = 'address_not_found';
    case RecipientRefused = 'recipient_refused';
    case NoAccess = 'no_access';
    case Other = 'other';

    /**
     * Get the human readable, customer-safe label.
     */
    public function label(): string
    {
        return match ($this) {
            self::RecipientUnavailable => 'Recipient not available',
            self::AddressNotFound => 'Address could not be found',
            self::RecipientRefused => 'Recipient refused the parcel',
            self::NoAccess => 'No access to the premises',
            self::Other => 'Other reason',
        };
    }
}
