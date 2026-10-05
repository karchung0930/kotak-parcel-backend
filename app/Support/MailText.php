<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Text that users typed, made safe to print in a Markdown email, such as a
 * city in a driver's email.
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
}
