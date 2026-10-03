<?php

namespace App\Http\Requests\Admin;

use App\Models\RateCard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRateCardRequest extends FormRequest
{
    /**
     * Determine if the user may start a draft.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', RateCard::class);
    }

    /**
     * Get the validation rules that apply to the request: the version to
     * copy, or none for a blank draft.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'source_id' => ['nullable', 'integer', Rule::exists('rate_cards', 'id')],
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
            'source_id.integer' => 'Choose a version to copy.',
            'source_id.exists' => 'That version no longer exists. Choose another one.',
        ];
    }

    /**
     * Get the card to copy, or null to start blank.
     */
    public function source(): ?RateCard
    {
        $id = $this->validated('source_id');

        return $id === null ? null : RateCard::query()->whereKey($id)->firstOrFail();
    }
}
