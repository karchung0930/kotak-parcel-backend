<?php

namespace App\Rules;

use App\Support\Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Malaysian phone number, checked with Google's libphonenumber
 * (propaganistas/laravel-phone over giggsey/libphonenumber-for-php).
 *
 * It runs the same checks as the package's "phone:MY,mobile" rule, with
 * Malaysia fixed. The package rule also takes countries from the request:
 * from a "<field>_country" input, and a field named "MY" turns the "MY"
 * parameter into a country field. Either would let a client have a foreign
 * number accepted.
 *
 * Normalise the input with App\Support\Phone::normalize() first, so the
 * number is stored in E.164 form. The messages match the browser's
 * (resources/js/lib/phone.ts in the frontend repository).
 */
class MalaysianPhone implements ValidationRule
{
    /**
     * @param  list<string>  $types  libphonenumber number types
     */
    private function __construct(
        private readonly array $types,
        private readonly string $message,
    ) {}

    /**
     * Account numbers (register, profile, admin user form): mobiles only.
     */
    public static function mobile(): self
    {
        return new self(['mobile'], 'Enter a valid Malaysian mobile number, e.g. 12-345 6789.');
    }

    /**
     * A parcel's receiver: a mobile or a landline (not 1-300 or premium-rate numbers).
     */
    public static function mobileOrLandline(): self
    {
        return new self(['mobile', 'fixed_line'], 'Enter a valid Malaysian mobile or landline number.');
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! Phone::isValid($value, $this->types)) {
            $fail($this->message);
        }
    }
}
