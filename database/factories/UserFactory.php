<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state (a verified customer).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'role' => Role::Customer,
            'phone' => fake()->numerify('+6012#######'),
            'branch_id' => null,
            'vehicle_plate' => null,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Indicate that the user is a customer.
     */
    public function customer(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Customer,
        ]);
    }

    /**
     * Indicate that the user works the counter at a branch.
     */
    public function staff(?Branch $branch = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Staff,
            'branch_id' => $branch ?? Branch::factory(),
        ]);
    }

    /**
     * Indicate that the user is an admin.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Admin,
        ]);
    }

    /**
     * Indicate that the user is a delivery driver.
     */
    public function driver(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Driver,
            'vehicle_plate' => strtoupper(fake()->bothify('W?? ####')),
        ]);
    }

    /**
     * Indicate that the account has been deactivated.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
