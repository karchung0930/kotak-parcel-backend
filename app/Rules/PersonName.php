<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A person's name as other people read it, also in emails (a customer's
 * name is the sender's name in the receiver's emails): one line, with no web
 * address and none of the characters that HTML or Markdown read as markup.
 *
 * Emails clean the name again where they print it (App\Support\MailText),
 * which also covers names saved before this rule.
 */
class PersonName implements ValidationRule
{
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        // Control characters (line breaks included), markup, and the characters that reverse text.
        if (preg_match('~[\x00-\x1F\x7F<>\[\]|\x{202A}-\x{202E}\x{2066}-\x{2069}]~u', $value) !== 0) {
            // No-break spaces keep the characters together on a narrow screen.
            $fail("Remove line breaks and the characters <\u{00A0}>\u{00A0}[\u{00A0}]\u{00A0}| from the :attribute.");

            return;
        }

        if (preg_match('~://|www\.~i', $value) === 1) {
            $fail('The :attribute cannot contain a web address.');
        }
    }
}
