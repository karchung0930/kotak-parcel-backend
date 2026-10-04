<?php

namespace Database\Factories;

use App\Enums\RateImportStatus;
use App\Models\RateCard;
use App\Models\RateImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Rate card imports for tests: an uploaded file waiting to be read, based
 * on the first rate card (the Standard rates from the migration). Tests
 * that read a file put one on the faked disk and set its path.
 *
 * @extends Factory<RateImport>
 */
class RateImportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->admin(),
            'base_rate_card_id' => fn () => RateCard::query()->orderBy('id')->value('id'),
            'original_name' => 'rates.xlsx',
            'path' => 'rate-imports/'.fake()->uuid().'.xlsx',
            'status' => RateImportStatus::Uploaded,
        ];
    }

    /**
     * With the given status.
     */
    public function status(RateImportStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
