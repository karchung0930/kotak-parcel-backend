<?php

namespace App\Http\Requests\Admin;

use App\Enums\MalaysianState;
use App\Models\Branch;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Creates (POST) or updates (PUT/PATCH) a branch from the admin panel.
 */
class BranchRequest extends FormRequest
{
    /**
     * Determine if the user may create or update the branch.
     */
    public function authorize(): bool
    {
        $branch = $this->branch();

        return $branch === null
            ? $this->user()->can('create', Branch::class)
            : $this->user()->can('update', $branch);
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $code = $this->input('code');

        $this->merge([
            'code' => is_string($code) ? Str::upper(trim($code)) : $code,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Coordinates must fall within Malaysia so the nearest-branch finder stays meaningful.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:16',
                'regex:/^[A-Z0-9]+(-[A-Z0-9]+)*$/',
                Rule::unique(Branch::class)->ignore($this->branch()?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', Rule::enum(MalaysianState::class)],
            'postcode' => ['required', 'regex:/^\d{5}$/'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9][0-9 -]{7,18}$/'],
            'latitude' => ['required', 'numeric', 'between:0.8,7.5'],
            'longitude' => ['required', 'numeric', 'between:99.5,119.5'],
            'opening_hours' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
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
            'code.regex' => 'Use capital letters and numbers separated by hyphens, e.g. PJ-SS2.',
            'postcode.regex' => 'Enter a 5-digit postcode.',
            'latitude.between' => 'The location must be in Malaysia.',
            'longitude.between' => 'The location must be in Malaysia.',
        ];
    }

    /**
     * Get the branch being edited, or null when creating one.
     */
    private function branch(): ?Branch
    {
        $branch = $this->route('branch');

        return $branch instanceof Branch ? $branch : null;
    }
}
