<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReturnToSenderRequest extends FormRequest
{
    /**
     * Determine if the user may return the parcel to its sender.
     */
    public function authorize(): bool
    {
        return $this->user()->can('returnToSender', $this->route('order'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The note is shown to the customer in their order history.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
