<?php

namespace App\Http\Requests\Driver;

use App\Enums\MoveDirection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveJobRequest extends FormRequest
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
            'direction' => ['required', Rule::enum(MoveDirection::class)],
        ];
    }
}
