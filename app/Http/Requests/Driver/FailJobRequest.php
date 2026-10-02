<?php

namespace App\Http\Requests\Driver;

use App\Enums\DeliveryFailureReason;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FailJobRequest extends FormRequest
{
    /**
     * Determine if the user is the driver assigned to this delivery.
     */
    public function authorize(): bool
    {
        return $this->user()->can('deliver', $this->route('order'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(DeliveryFailureReason::class)],
            'note' => [
                'nullable',
                'string',
                'max:500',
                'required_if:reason,'.DeliveryFailureReason::Other->value,
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required_if' => 'Describe what happened when the reason is "Other".',
        ];
    }
}
