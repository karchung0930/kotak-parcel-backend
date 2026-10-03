<?php

namespace Tests\Feature\Support;

use App\Actions\Settings\UpdateSettings;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsaved_settings_use_the_defaults_from_config()
    {
        $this->assertSame([
            'unclaimed_order_days' => 7,
            'drop_off_reminder_days_before' => 2,
            'max_failed_attempts' => 3,
        ], app(Settings::class)->all());
    }

    public function test_saved_values_replace_the_defaults()
    {
        $this->saveRow('unclaimed_order_days', 10);
        $this->saveRow('max_failed_attempts', 5);

        $settings = app(Settings::class);

        $this->assertSame(10, $settings->unclaimedOrderDays());
        $this->assertSame(2, $settings->dropOffReminderDaysBefore());
        $this->assertSame(5, $settings->maxFailedAttempts());
    }

    public function test_the_saved_values_are_read_once_and_cached()
    {
        $this->saveRow('unclaimed_order_days', 10);

        $queries = $this->countQueries(function () {
            app(Settings::class)->unclaimedOrderDays();
            app(Settings::class)->maxFailedAttempts();
            app(Settings::class)->all();
        });

        $this->assertSame(1, $queries);
        $this->assertSame(['unclaimed_order_days' => 10], Cache::get(Settings::CACHE_KEY));

        // A fresh instance (the next request) reads the cache, not the database.
        $this->app->forgetScopedInstances();
        $this->assertSame(0, $this->countQueries(fn () => app(Settings::class)->all()));
    }

    public function test_a_change_made_behind_the_cache_is_seen_after_a_refresh_or_ten_minutes()
    {
        $settings = app(Settings::class);
        $this->assertSame(7, $settings->unclaimedOrderDays());

        $this->saveRow('unclaimed_order_days', 12);
        $this->assertSame(7, $settings->unclaimedOrderDays());

        $settings->refresh();

        $this->assertSame(['unclaimed_order_days' => 12], Cache::get(Settings::CACHE_KEY));
        $this->assertSame(12, $settings->unclaimedOrderDays());

        // Without a refresh, the cached values last ten minutes.
        Setting::query()->findOrFail('unclaimed_order_days')->forceFill(['value' => 20])->save();
        $this->app->forgetScopedInstances();
        $this->assertSame(12, app(Settings::class)->unclaimedOrderDays());

        $this->travel(Settings::CACHE_SECONDS + 1)->seconds();
        $this->app->forgetScopedInstances();
        $this->assertSame(20, app(Settings::class)->unclaimedOrderDays());
    }

    public function test_saving_through_the_action_caches_the_new_values_and_records_the_admin()
    {
        $admin = User::factory()->admin()->create();
        $settings = app(Settings::class);
        $this->assertSame(3, $settings->maxFailedAttempts());

        app(UpdateSettings::class)->handle($admin, [
            'unclaimed_order_days' => 14,
            'drop_off_reminder_days_before' => 3,
            'max_failed_attempts' => 3,
        ]);

        $this->assertSame(['unclaimed_order_days' => 14, 'drop_off_reminder_days_before' => 3, 'max_failed_attempts' => 3], $settings->all());
        $this->assertSame(['unclaimed_order_days' => 14, 'drop_off_reminder_days_before' => 3, 'max_failed_attempts' => 3], Cache::get(Settings::CACHE_KEY));
        $this->assertSame($admin->id, Setting::query()->findOrFail('unclaimed_order_days')->updated_by);

        // A value equal to the default is stored too: it is the admin's choice now.
        $this->assertSame(3, Setting::query()->findOrFail('max_failed_attempts')->value);
    }

    public function test_a_saved_value_does_not_follow_a_later_change_to_the_default()
    {
        app(UpdateSettings::class)->handle(User::factory()->admin()->create(), ['unclaimed_order_days' => 7]);

        config(['kotak.unclaimed_order_days' => 14]);
        $this->app->forgetScopedInstances();

        $this->assertSame(7, app(Settings::class)->unclaimedOrderDays());
    }

    public function test_an_unchanged_value_keeps_who_changed_it_last()
    {
        [$first, $second] = User::factory()->admin()->count(2)->create();

        app(UpdateSettings::class)->handle($first, ['unclaimed_order_days' => 10, 'max_failed_attempts' => 4]);
        $this->travel(1)->hours();
        app(UpdateSettings::class)->handle($second, ['unclaimed_order_days' => 10, 'max_failed_attempts' => 5]);

        $this->assertSame($first->id, Setting::query()->findOrFail('unclaimed_order_days')->updated_by);
        $this->assertSame($second->id, Setting::query()->findOrFail('max_failed_attempts')->updated_by);
    }

    public function test_a_stale_cache_never_hides_a_real_change()
    {
        $this->saveRow('unclaimed_order_days', 7);
        // The cache still says 10, as if it had not caught up.
        Cache::put(Settings::CACHE_KEY, ['unclaimed_order_days' => 10]);

        app(UpdateSettings::class)->handle(User::factory()->admin()->create(), ['unclaimed_order_days' => 10]);

        $this->assertSame(10, Setting::query()->findOrFail('unclaimed_order_days')->value);
        $this->assertSame(10, app(Settings::class)->unclaimedOrderDays());
    }

    public function test_a_read_that_started_before_a_save_cannot_cache_the_old_values()
    {
        $this->saveRow('unclaimed_order_days', 10);
        $admin = User::factory()->admin()->create();

        // Another request reads the table; the save commits before it caches what it read.
        $saved = false;
        Setting::retrieved(function () use ($admin, &$saved) {
            if (! $saved) {
                $saved = true;
                app(UpdateSettings::class)->handle($admin, ['unclaimed_order_days' => 12]);
            }
        });

        $this->assertSame(10, (new Settings)->unclaimedOrderDays());

        $this->assertTrue($saved);
        $this->assertSame(['unclaimed_order_days' => 12], Cache::get(Settings::CACHE_KEY));
        $this->app->forgetScopedInstances();
        $this->assertSame(12, app(Settings::class)->unclaimedOrderDays());
    }

    public function test_the_reminder_always_comes_before_the_order_expires()
    {
        $this->saveRow('unclaimed_order_days', 3);
        $this->saveRow('drop_off_reminder_days_before', 5);

        $this->assertSame(2, app(Settings::class)->dropOffReminderDaysBefore());
    }

    /**
     * Store a value directly, as if it had been saved earlier.
     */
    private function saveRow(string $key, int $value): void
    {
        $setting = new Setting;
        $setting->forceFill(['key' => $key, 'value' => $value])->save();
    }

    /**
     * Count the database queries the callback runs.
     */
    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
