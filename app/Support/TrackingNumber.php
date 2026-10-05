<?php

namespace App\Support;

use App\Models\Order;
use RuntimeException;

/**
 * Tracking numbers look like "KT7Q4M92XD" in storage and "KT-7Q4M92XD" on screen.
 *
 * The 8 random characters use Crockford's base32 alphabet, which leaves out
 * I, L, O and U so numbers are easy to read aloud and hard to mistype.
 */
class TrackingNumber
{
    public const PREFIX = 'KT';

    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const LENGTH = 8;

    /**
     * Generate a random tracking number (not checked for uniqueness).
     */
    public static function generate(): string
    {
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return self::PREFIX.$code;
    }

    /**
     * Generate a tracking number that is not used by any order yet.
     */
    public static function unique(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $number = self::generate();

            if (! Order::query()->where('tracking_number', $number)->exists()) {
                return $number;
            }
        }

        throw new RuntimeException('Unable to generate a unique tracking number.');
    }

    /**
     * Normalise user input ("kt-7q4m92xd", "KT 7Q4M 92XD") to the stored form.
     * Any hyphen or dash counts, such as the non-breaking one that emails
     * print (formatForMail()), so a number copied from an email still works.
     *
     * Returns null when the input cannot be a valid tracking number.
     */
    public static function normalize(?string $input): ?string
    {
        $value = strtoupper((string) preg_replace('/[\s\x{2010}-\x{2015}\x{2212}-]+/u', '', (string) $input));

        if (! str_starts_with($value, self::PREFIX)) {
            return null;
        }

        // Crockford decoding: commonly confused letters map to their digits.
        $code = strtr(substr($value, strlen(self::PREFIX)), ['O' => '0', 'I' => '1', 'L' => '1']);

        if (preg_match('/^['.self::ALPHABET.']{'.self::LENGTH.'}$/', $code) !== 1) {
            return null;
        }

        return self::PREFIX.$code;
    }

    /**
     * Format a stored tracking number for display, e.g. "KT-7Q4M92XD".
     */
    public static function format(string $number): string
    {
        return self::PREFIX.'-'.substr($number, strlen(self::PREFIX));
    }

    /**
     * Format a stored tracking number for the text of an email, e.g.
     * "KT-7Q4M92XD" with a non-breaking hyphen, so a narrow screen never
     * leaves "KT-" alone at the end of a line. Subjects keep the plain
     * hyphen, so searching the inbox for the number still works.
     */
    public static function formatForMail(string $number): string
    {
        return self::PREFIX."\u{2011}".substr($number, strlen(self::PREFIX));
    }
}
