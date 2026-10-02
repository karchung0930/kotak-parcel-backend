<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ProfileValidationRules;
use App\Enums\Role;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creates (POST) or updates (PUT/PATCH) an account from the admin panel.
 */
class UserRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Determine if the user may create or update the account.
     */
    public function authorize(): bool
    {
        $account = $this->account();

        return $account === null
            ? $this->user()->can('create', User::class)
            : $this->user()->can('update', $account);
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $plate = $this->input('vehicle_plate');

        $this->merge([
            'phone' => Phone::normalize($this->input('phone')),
            'vehicle_plate' => is_string($plate) ? Str::upper(Str::squish($plate)) : $plate,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Staff need a branch and drivers a vehicle. Admins may optionally have a
     * branch so their counter work is recorded against it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules($this->account()?->id),
            'role' => ['required', Rule::enum(Role::class)],
            'branch_id' => [
                'nullable',
                'required_if:role,'.Role::Staff->value,
                'integer',
                Rule::exists('branches', 'id')->where('is_active', true),
            ],
            'vehicle_plate' => [
                'nullable',
                'required_if:role,'.Role::Driver->value,
                'string',
                'max:16',
                'regex:/^[A-Z0-9 ]+$/',
            ],
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
            'branch_id.required_if' => 'Choose the branch this staff member works at.',
            'branch_id.exists' => 'Choose an active branch.',
            'vehicle_plate.required_if' => 'Enter the vehicle plate number for this driver.',
            'vehicle_plate.regex' => 'The vehicle plate may only contain letters, numbers and spaces.',
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $account = $this->account();

                if ($account === null) {
                    return;
                }

                $keepsRole = $this->input('role') === $account->role->value;

                // Admins cannot lock themselves out of the admin panel.
                if ($this->user()->is($account)) {
                    if (! $keepsRole) {
                        $validator->errors()->add('role', 'You cannot change your own role.');
                    }

                    if (! $this->boolean('is_active')) {
                        $validator->errors()->add('is_active', 'You cannot deactivate your own account.');
                    }
                }

                // A driver's deliveries in progress would be left without anyone to complete them.
                if ($account->isDriver() && (! $keepsRole || ! $this->boolean('is_active'))) {
                    $activeJobs = $account->assignedOrders()->activeJobs()->count();

                    if ($activeJobs > 0) {
                        $validator->errors()->add('is_active', sprintf(
                            'This driver still has %d %s in progress.',
                            $activeJobs,
                            Str::plural('delivery', $activeJobs),
                        ));
                    }
                }
            },
        ];
    }

    /**
     * Get the validated account attributes, including the admin-only ones.
     *
     * A branch is kept only for staff and admins, and a vehicle only for drivers.
     *
     * @return array<string, mixed>
     */
    public function accountData(): array
    {
        $role = Role::from($this->string('role')->value());

        return [
            ...$this->safe()->only(['name', 'email', 'phone']),
            'role' => $role,
            'branch_id' => in_array($role, [Role::Staff, Role::Admin], true) ? $this->validated('branch_id') : null,
            'vehicle_plate' => $role === Role::Driver ? $this->validated('vehicle_plate') : null,
            'is_active' => $this->boolean('is_active'),
        ];
    }

    /**
     * Get the account being edited, or null when creating one.
     */
    private function account(): ?User
    {
        $account = $this->route('user');

        return $account instanceof User ? $account : null;
    }
}
