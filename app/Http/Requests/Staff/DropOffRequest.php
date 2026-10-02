<?php

namespace App\Http\Requests\Staff;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DropOffRequest extends FormRequest
{
    /**
     * Determine if the user may receive and weigh the parcel.
     */
    public function authorize(): bool
    {
        return $this->user()->can('process', $this->route('order'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Dimensions are optional: they are only sent when staff correct the
     * customer's measurements.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $dimension = ['nullable', 'integer', 'min:1', 'max:'.config()->integer('kotak.max_dimension_cm')];

        return [
            'measured_weight_g' => ['required', 'integer', 'min:1', 'max:'.config()->integer('kotak.max_weight_g')],
            'length_cm' => $dimension,
            'width_cm' => $dimension,
            'height_cm' => $dimension,
        ];
    }

    /**
     * Get a corrected dimension in cm, or null to keep the customer's measurement.
     */
    public function dimension(string $key): ?int
    {
        return $this->filled($key) ? $this->integer($key) : null;
    }
}
