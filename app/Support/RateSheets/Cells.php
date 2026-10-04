<?php

namespace App\Support\RateSheets;

use Illuminate\Support\Str;

/**
 * Reading and writing single spreadsheet cells: numbers typed in many ways
 * ("RM 8.50", "1,200", "Up to 2 kg", "500g"), the cell that marks the price
 * per extra kg, the mark for "no band at this weight", column letters, and
 * escaping text so a spreadsheet program never runs it as a formula.
 */
final class Cells
{
    /**
     * The characters that make a spreadsheet program treat a cell as a
     * formula (or a command, in CSV files opened by Excel).
     */
    private const FORMULA_STARTS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Words that, with "kg", mark the price per extra kg: "Each additional
     * kg", "Additional 1kg", "Per kg", "Every kg thereafter". A weight right
     * after one of them ("Per 0.5 kg") is the step the price is for. Words
     * that only give where the price starts ("Over 30 kg", "5 kg and
     * above") do not count: such a row may as well be a band.
     */
    private const EXTRA_WORDS = ['additional', 'extra', 'each', 'every', 'per', 'subsequent', 'thereafter', 'next', 'tambahan', 'setiap'];

    private const UNIT_WORDS = ['kg', 'kgs', 'kilo', 'kilos', 'kilogram', 'kilograms', 'g', 'gm', 'gram', 'grams'];

    /**
     * What a matrix box holds where a route has no band at that weight.
     * An empty box is a missing price instead.
     */
    private const NO_BAND = ['n/a', 'na', 'none', 'nil', '-', '–', '—'];

    /**
     * Get a cell as text: whole numbers without decimals, others with at
     * most six, and an empty cell as "".
     */
    public static function text(string|int|float|null $value): string
    {
        return match (true) {
            $value === null => '',
            is_int($value) => (string) $value,
            is_float($value) => rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.'),
            default => trim($value),
        };
    }

    /**
     * Get a cell as text short enough to quote in a message.
     */
    public static function quote(string|int|float|null $value): string
    {
        return Str::limit(self::text($value), 40);
    }

    /**
     * Determine if a cell is empty.
     */
    public static function isEmpty(string|int|float|null $value): bool
    {
        return self::text($value) === '';
    }

    /**
     * Read a number and the unit typed with it, if any: 8.5 → [8.5, null],
     * "RM 1,200.50" → [1200.5, 'rm'], "500 g" → [500, 'g'], "Up to 2kg" →
     * [2, 'kg'], "1.01 - 2 kg" (a band written as a range) → [2, 'kg'].
     * Null when the cell holds anything else, such as "abc" or "Zone 2".
     *
     * A comma only groups thousands ("1,200"), unless the file writes
     * decimals with a comma, as a CSV file separated by semicolons does
     * where Excel is set up that way: then "8,50" is 8.5. Elsewhere "8,50"
     * is not a number, rather than 850.
     *
     * @return array{float, 'kg'|'g'|'rm'|'sen'|null}|null
     */
    public static function number(string|int|float|null $value, bool $decimalComma = false): ?array
    {
        if (is_int($value) || is_float($value)) {
            return [(float) $value, null];
        }

        $unit = null;
        $text = mb_strtolower(self::unescape(self::text($value)));

        $rest = (string) preg_replace_callback(
            '/(?<!\pL)(rm|myr|ringgit|sen|kilograms?|kilos?|kgs?|grams?|gm|g)(?!\pL)/u',
            function (array $match) use (&$unit): string {
                $unit = match (true) {
                    in_array($match[1], ['rm', 'myr', 'ringgit'], true) => 'rm',
                    $match[1] === 'sen' => 'sen',
                    str_starts_with($match[1], 'k') => 'kg',
                    default => 'g',
                };

                return ' ';
            },
            $text,
        );
        $rest = (string) preg_replace('/(?<!\pL)(up to|upto|max|maximum|until|under|below|less than|first|and below|and under|or less|or below)(?!\pL)|[≤<~:&]/u', ' ', $rest);
        $rest = trim((string) preg_replace('/\s+/', ' ', $rest));

        if (preg_match('/^(-?)(\d[\d.,]*)(?:\s*(?:-|–|—|to)\s*(\d[\d.,]*))?$/u', $rest, $match) !== 1) {
            return null;
        }

        $first = self::decimal($match[2], $decimalComma);
        $first = $first !== null && $match[1] === '-' ? -$first : $first;
        // A range ends at its last number.
        $last = isset($match[3]) ? self::decimal($match[3], $decimalComma) : $first;

        if ($first === null || $last === null) {
            return null;
        }

        return [$last, $unit];
    }

    /**
     * Determine if a weight cell marks the price per extra kg, e.g. "Each
     * additional kg", "Additional 1kg", "Per kg" or just "Additional".
     */
    public static function isExtraKg(string|int|float|null $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        // "1kg" counts as "1 kg".
        $words = explode(' ', (string) preg_replace('/(\d)(\pL)/u', '$1 $2', self::words($value)));
        $marked = array_intersect($words, self::EXTRA_WORDS) !== [];

        return $marked && (array_intersect($words, self::UNIT_WORDS) !== [] || preg_match('/\d/', $value) !== 1);
    }

    /**
     * Get the weight a price-per-extra-kg cell says the price is for, in
     * grams, when it says one: "Per 0.5 kg" → 500, "Each additional 500 g"
     * → 500, "Additional 1kg" → 1000. Null for "Each additional kg", which
     * means each kg.
     */
    public static function extraStepG(string|int|float|null $value): ?int
    {
        if (! is_string($value)) {
            return null;
        }

        $words = implode('|', self::EXTRA_WORDS);
        $text = mb_strtolower(self::unescape(trim($value)));

        if (preg_match("/(?<!\\pL)(?:{$words})\\s*(\\d+(?:[.,]\\d+)?)\\s*(kilograms?|kilos?|kgs?|grams?|gm|g)?(?!\\pL)/u", $text, $match) !== 1) {
            return null;
        }

        $amount = (float) str_replace(',', '.', $match[1]);

        return (int) round(str_starts_with($match[2] ?? 'kg', 'g') ? $amount : $amount * 1000);
    }

    /**
     * Determine if a weight cell gives where prices start rather than a
     * weight: "Over 30 kg", "5 kg and above", "10kg onwards".
     */
    public static function isAboveWeight(string|int|float|null $value): bool
    {
        return is_string($value) && preg_match('/(?<!\pL)(over|above|onwards?|more than|exceeding)(?!\pL)/iu', $value) === 1;
    }

    /**
     * Determine if a matrix box says the route has no band at its weight:
     * "n/a", "-" or "—".
     */
    public static function isNoBand(string|int|float|null $value): bool
    {
        return is_string($value) && in_array(mb_strtolower(self::unescape(trim($value))), self::NO_BAND, true);
    }

    /**
     * Get a spreadsheet column's letter: 0 → A, 25 → Z, 26 → AA.
     */
    public static function column(int $index): string
    {
        $letters = '';

        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + ($n - 1) % 26).$letters;
        }

        return $letters;
    }

    /**
     * Escape text for a spreadsheet cell: text starting with =, +, -, @, a
     * tab or a carriage return gets an apostrophe in front, so Excel and
     * other programs show it rather than run it as a formula.
     */
    public static function escape(string $text): string
    {
        return $text !== '' && in_array($text[0], self::FORMULA_STARTS, true) ? "'{$text}" : $text;
    }

    /**
     * Undo escape(): an apostrophe in front of a formula character goes, so
     * an exported file reads back as it was.
     */
    public static function unescape(string $text): string
    {
        return strlen($text) > 1 && $text[0] === "'" && in_array($text[1], self::FORMULA_STARTS, true)
            ? substr($text, 1)
            : $text;
    }

    /**
     * Normalise text for matching: lower case words without accents or
     * punctuation, so "Max. weight (kg)" → "max weight kg".
     */
    public static function words(string $text): string
    {
        $plain = Str::lower(Str::ascii(self::unescape($text)));

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', $plain)));
    }

    /**
     * Read the digits of a number with its separators, or null when they
     * do not make one. With both a comma and a point, the last is the
     * decimal point and the other groups thousands ("1,200.50",
     * "1.200,50"). A comma alone groups thousands ("1,200"), or with
     * decimal commas is the decimal point ("8,50").
     */
    private static function decimal(string $digits, bool $decimalComma): ?float
    {
        $point = strrpos($digits, '.');
        $comma = strrpos($digits, ',');

        if ($point !== false && $comma !== false) {
            [$group, $decimal] = $point > $comma ? [',', '.'] : ['.', ','];
            $pattern = '/^\d{1,3}(?:'.preg_quote($group, '/').'\d{3})+'.preg_quote($decimal, '/').'\d+$/';

            return preg_match($pattern, $digits) === 1 ? (float) str_replace([$group, $decimal], ['', '.'], $digits) : null;
        }

        if ($comma !== false) {
            if ($decimalComma) {
                return preg_match('/^\d+,\d+$/', $digits) === 1 ? (float) str_replace(',', '.', $digits) : null;
            }

            return preg_match('/^\d{1,3}(?:,\d{3})+$/', $digits) === 1 ? (float) str_replace(',', '', $digits) : null;
        }

        return preg_match('/^\d+(?:\.\d+)?$/', $digits) === 1 ? (float) $digits : null;
    }
}
