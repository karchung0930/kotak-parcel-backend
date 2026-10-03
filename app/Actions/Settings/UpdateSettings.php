<?php

namespace App\Actions\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

class UpdateSettings
{
    /**
     * Create a new action instance.
     */
    public function __construct(
        private Settings $settings,
    ) {}

    /**
     * Save the business rules an admin submitted and put them in the cache.
     *
     * Each value is compared with its locked row, not the cached copy. A
     * value with no row yet is stored even when it equals the default, so
     * a later change to config/kotak.php never changes a rule an admin
     * chose. An unchanged row is left alone, so it keeps who last changed
     * that rule and when.
     *
     * @param  array<string, int>  $values  validated by UpdateSettingsRequest
     */
    public function handle(User $admin, array $values): void
    {
        DB::transaction(function () use ($admin, $values): void {
            foreach (array_intersect_key($values, Settings::LIMITS) as $key => $value) {
                $setting = Setting::query()->lockForUpdate()->find($key);

                if ($setting?->value === $value) {
                    continue;
                }

                ($setting ?? new Setting)->forceFill(['key' => $key, 'value' => $value, 'updated_by' => $admin->id])->save();
            }
        });

        $this->settings->refresh();
    }
}
