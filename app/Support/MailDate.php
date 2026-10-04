<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Dates as email bodies print them, e.g. "Tuesday, 6 October 2026".
 *
 * The spaces inside the date are no-break spaces, so a narrow screen wraps
 * it only after the weekday's comma, never between "6" and "October".
 * Subjects keep plain spaces, so searching the inbox for a date still works.
 */
class MailDate
{
    /**
     * Format the date with the weekday and year, e.g. "Tuesday, 6 October 2026".
     */
    public static function long(CarbonInterface $date): string
    {
        return self::format($date, 'l, j F Y');
    }

    /**
     * Format the date without the weekday, e.g. "2 Oct".
     */
    public static function short(CarbonInterface $date): string
    {
        return self::format($date, 'j M');
    }

    /**
     * Format the date, keeping every space that does not follow a comma.
     */
    private static function format(CarbonInterface $date, string $format): string
    {
        return (string) preg_replace('/(?<!,) /', "\u{00A0}", $date->format($format));
    }
}
