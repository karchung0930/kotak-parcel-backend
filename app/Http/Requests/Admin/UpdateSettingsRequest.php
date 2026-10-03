<?php

namespace App\Http\Requests\Admin;

use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingsRequest extends FormRequest
{
    /**
     * Determine if the user may change the business rules.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', Setting::class);
    }

    /**
     * Prepare the data for validation: "07" is 7, which the integer rule
     * would otherwise turn down.
     */
    protected function prepareForValidation(): void
    {
        $numbers = [];

        foreach (array_keys(Settings::LIMITS) as $key) {
            $value = $this->input($key);

            if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
                $numbers[$key] = (string) (int) $value;
            }
        }

        $this->merge($numbers);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The reminder has to go out before the order expires, so it must be
     * fewer days before than the expiry itself. That is only checked once
     * the limit is a whole number, so a missing limit gives one error.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $limits = Settings::LIMITS;
        $between = fn (string $key): string => sprintf('between:%d,%d', $limits[$key]['min'], $limits[$key]['max']);
        $limitIsWhole = filter_var($this->input('unclaimed_order_days'), FILTER_VALIDATE_INT) !== false;

        return [
            'unclaimed_order_days' => ['required', 'integer', $between('unclaimed_order_days')],
            'drop_off_reminder_days_before' => [
                'required', 'integer', $between('drop_off_reminder_days_before'),
                Rule::when($limitIsWhole, ['lt:unclaimed_order_days']),
            ],
            'max_failed_attempts' => ['required', 'integer', $between('max_failed_attempts')],
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
            '*.required' => 'Enter a number.',
            '*.integer' => 'Enter a whole number.',
            'unclaimed_order_days.between' => 'Choose between :min and :max days.',
            'drop_off_reminder_days_before.between' => 'Choose between :min and :max days.',
            'drop_off_reminder_days_before.lt' => 'Use fewer days than the drop-off limit, so the reminder comes first.',
            'max_failed_attempts.between' => 'Choose between :min and :max attempts.',
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
            'unclaimed_order_days' => 'days to drop off',
            'drop_off_reminder_days_before' => 'reminder',
            'max_failed_attempts' => 'delivery attempts',
        ];
    }

    /**
     * Get the validated settings as whole numbers.
     *
     * @return array<string, int>
     */
    public function settings(): array
    {
        return array_map(intval(...), $this->validated());
    }
}
