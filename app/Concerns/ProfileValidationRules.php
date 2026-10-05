<?php

namespace App\Concerns;

use App\Models\User;
use App\Rules\MalaysianPhone;
use App\Rules\PersonName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
            'phone' => $this->phoneRules(),
        ];
    }

    /**
     * Get the validation rules used to validate user names. A customer's
     * name is also the sender's name on their parcels, which receivers read
     * in their emails: as long as a receiver's name at most, and plain.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:100', new PersonName];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }

    /**
     * Get the validation rules used to validate Malaysian mobile numbers.
     *
     * Normalise the input with App\Support\Phone::normalize() before validating.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function phoneRules(): array
    {
        return ['required', 'string', MalaysianPhone::mobile()];
    }
}
