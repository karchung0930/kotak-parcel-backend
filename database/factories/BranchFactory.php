<?php

namespace Database\Factories;

use App\Enums\MalaysianState;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $city = fake()->randomElement(['Petaling Jaya', 'Kuala Lumpur', 'Subang Jaya', 'Shah Alam', 'Klang']);

        return [
            'code' => strtoupper(fake()->unique()->bothify('KV-??##')),
            'name' => $city.' - '.fake()->streetSuffix(),
            'address' => fake()->streetAddress(),
            'city' => $city,
            'state' => $city === 'Kuala Lumpur' ? MalaysianState::KualaLumpur : MalaysianState::Selangor,
            'postcode' => fake()->numerify('4####'),
            'phone' => fake()->numerify('03-#### ####'),
            // Somewhere in the Klang Valley.
            'latitude' => fake()->randomFloat(7, 2.95, 3.25),
            'longitude' => fake()->randomFloat(7, 101.45, 101.80),
            'opening_hours' => 'Mon-Sat 9:00-21:00, Sun 10:00-18:00',
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the branch no longer accepts parcels.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
