<?php

namespace App\Http\Requests\Customer;

use App\Enums\MalaysianState;
use App\Models\Order;
use App\Rules\MalaysianPhone;
use App\Support\Phone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Order::class);
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['receiver_phone' => Phone::normalize($this->input('receiver_phone'))]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Only these fields reach the order: the sender, status, prices and
     * tracking number are always set by the server.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $dimension = ['required', 'integer', 'min:1', 'max:'.config()->integer('kotak.max_dimension_cm')];

        return [
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'receiver_name' => ['required', 'string', 'max:100'],
            'receiver_phone' => ['required', 'string', MalaysianPhone::mobileOrLandline()],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            // Shown to drivers in their emails: one line, nothing that could act as markup.
            'city' => ['required', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F<>\[\]|]/'],
            'state' => ['required', 'string', Rule::enum(MalaysianState::class)],
            'postcode' => ['required', 'string', 'regex:/^\d{5}$/'],
            'item_name' => ['required', 'string', 'max:100'],
            'declared_weight_g' => ['required', 'integer', 'min:1', 'max:'.config()->integer('kotak.max_weight_g')],
            'length_cm' => $dimension,
            'width_cm' => $dimension,
            'height_cm' => $dimension,
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'branch_id' => 'drop-off branch',
            'receiver_phone' => 'receiver\'s phone number',
            'address_line1' => 'address',
            'address_line2' => 'address line 2',
            'declared_weight_g' => 'weight',
            'length_cm' => 'length',
            'width_cm' => 'width',
            'height_cm' => 'height',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxKg = config()->integer('kotak.max_weight_g') / 1000;

        return [
            'branch_id.exists' => 'Choose a branch that is open for drop-off.',
            // No-break spaces keep the characters together in the narrow city column.
            'city.not_regex' => "Remove the characters <\u{00A0}>\u{00A0}[\u{00A0}]\u{00A0}| from the city.",
            'postcode.regex' => 'Enter a 5-digit postcode.',
            'declared_weight_g.min' => 'Enter the parcel\'s weight.',
            'declared_weight_g.max' => "We accept parcels up to {$maxKg} kg.",
            '*_cm.max' => 'Each side of the parcel can be up to :max cm.',
        ];
    }
}
