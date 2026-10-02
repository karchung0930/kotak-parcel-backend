<?php

namespace App\Support;

use Propaganistas\LaravelPhone\PhoneNumber;
use Throwable;

/**
 * Malaysian phone numbers, checked with Google's libphonenumber (through
 * propaganistas/laravel-phone) and stored in E.164 form, e.g. "+60123456789".
 *
 * Forms validate them with App\Rules\MalaysianPhone. The browser checks them
 * the same way with libphonenumber-js (resources/js/lib/phone.ts in the
 * frontend repository).
 */
class Phone
{
    public const COUNTRY = 'MY';

    /**
     * "012-345 6789", "12 345 6789", "60123456789" or "+60 12-345 6789" → "+60123456789".
     *
     * Anything that is not a valid Malaysian number is returned unchanged,
     * so validation rejects it and the form shows what was typed.
     */
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return $value;
        }

        try {
            $phone = new PhoneNumber($value, self::COUNTRY);

            return $phone->isOfCountry(self::COUNTRY) ? $phone->formatE164() : $value;
        } catch (Throwable) {
            return $value;
        }
    }

    /**
     * Whether the number is a valid Malaysian number of one of the given
     * libphonenumber types ("mobile", "fixed_line", ...).
     *
     * @param  list<string>  $types
     */
    public static function isValid(string $number, array $types): bool
    {
        try {
            $phone = new PhoneNumber($number, self::COUNTRY);

            return $phone->isOfCountry(self::COUNTRY)
                && $phone->isOfType($types)
                && $phone->isValid();
        } catch (Throwable) {
            return false;
        }
    }
}
