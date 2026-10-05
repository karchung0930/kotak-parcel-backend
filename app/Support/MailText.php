<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Text that users typed, made safe to print in a Markdown email, such as a
 * city in a driver's email or the sender's name in the receiver's.
 *
 * Markdown emails escape HTML but not Markdown, and Laravel's secured
 * encoding, which escapes "[" too, only covers mail views compiled while an
 * email renders, not the ones `php artisan optimize` compiles ahead (see
 * AppServiceProvider::configureMail()). So the text is cleaned where it is
 * built instead.
 */
class MailText
{
    /**
     * Get the text as one line of plain text, without the characters that
     * Markdown or an HTML table would read as markup ([ ] < > |), so it can
     * never become a link, an image, a tag or a table cell.
     */
    public static function plain(?string $text): string
    {
        return Str::squish(str_replace(['[', ']', '<', '>', '|'], ' ', (string) $text));
    }

    /**
     * Get a person's name for an email that goes to someone else, as one
     * short line of plain text (plain()) that mail apps cannot turn into a
     * link either: web addresses are left out, a dot right before a letter
     * gets a space after it ("J.K. Lim" reads "J. K. Lim", and "pay.example"
     * is no longer a domain), and the invisible characters that reverse or
     * hide text are removed. Cut to $limit characters.
     */
    public static function name(?string $text, int $limit = 60): string
    {
        $text = (string) preg_replace('~(?:[a-z][a-z0-9+.-]*://|www\.)\S*~iu', ' ', self::plain($text));
        $text = (string) preg_replace('~[\x{061C}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]~u', '', $text);
        $text = (string) preg_replace('~([.\x{2024}\x{3002}\x{FF0E}\x{FF61}])(?=\p{L})~u', '$1 ', $text);

        return Str::limit(Str::squish($text), $limit, '…', preserveWords: true);
    }
}
