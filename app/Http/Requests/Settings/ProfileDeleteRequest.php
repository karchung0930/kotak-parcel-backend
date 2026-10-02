<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * Accounts linked to parcels, payments or deliveries are business records
     * and must be kept; staff and drivers are deactivated by an admin instead.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->user();

                if (! $user->isCustomer()) {
                    $validator->errors()->add('password', __('Staff accounts are deactivated by an administrator instead.'));
                } elseif ($user->orders()->exists()) {
                    $validator->errors()->add('password', __('Accounts with parcel records cannot be deleted. Please contact support.'));
                }
            },
        ];
    }
}
