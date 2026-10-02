<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignDriverRequest extends FormRequest
{
    /**
     * Determine if the user may schedule the delivery.
     */
    public function authorize(): bool
    {
        return $this->user()->can('assign', $this->route('order'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * "Today" is the business day in Malaysia, not the server's UTC date.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $today = today(config()->string('kotak.timezone'))->toDateString();

        return [
            'driver_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', Role::Driver->value)->where('is_active', true),
            ],
            'scheduled_for' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today],
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
            'driver_id.exists' => 'Choose an active driver.',
            'scheduled_for.after_or_equal' => 'The delivery date cannot be in the past.',
        ];
    }

    /**
     * Get the chosen driver.
     */
    public function driver(): User
    {
        return User::query()->findOrFail($this->integer('driver_id'));
    }

    /**
     * Get the delivery date as a Malaysian calendar day.
     */
    public function scheduledFor(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('scheduled_for')->value(), config()->string('kotak.timezone'));
    }
}
