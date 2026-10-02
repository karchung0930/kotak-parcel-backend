<?php

namespace App\Http\Requests\Driver;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ListJobsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Get the start of the requested day in Malaysia, defaulting to today.
     */
    public function day(): CarbonImmutable
    {
        $timezone = config()->string('kotak.timezone');

        return ($this->date('date', 'Y-m-d', $timezone) ?? today($timezone))->toImmutable()->startOfDay();
    }
}
