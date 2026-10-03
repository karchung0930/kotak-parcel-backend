<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * The business rules an admin can change on the Site settings page. Each
 * one falls back to its default in config/kotak.php until it is saved.
 *
 * The saved values are read in one query and cached under one key for ten
 * minutes. App\Actions\Settings\UpdateSettings puts the new values in the
 * cache after a save. The container keeps one instance per request or
 * queued job, so repeated reads stay in memory.
 */
class Settings
{
    /**
     * The cache key holding every saved value.
     */
    public const CACHE_KEY = 'kotak.settings';

    /**
     * How long the cached values are kept, in seconds: the longest a change
     * made behind the cache's back can go unseen.
     */
    public const CACHE_SECONDS = 600;

    /**
     * The values an admin may choose, by setting. The reminder must also
     * come before the order expires (see UpdateSettingsRequest).
     *
     * @var array<string, array{min: int, max: int}>
     */
    public const LIMITS = [
        'unclaimed_order_days' => ['min' => 2, 'max' => 60],
        'drop_off_reminder_days_before' => ['min' => 0, 'max' => 59],
        'max_failed_attempts' => ['min' => 1, 'max' => 10],
    ];

    /**
     * The saved values for this request, once loaded.
     *
     * @var array<string, mixed>|null
     */
    private ?array $saved = null;

    /**
     * Get how many days an order may wait for drop-off before it is cancelled.
     */
    public function unclaimedOrderDays(): int
    {
        return $this->integer('unclaimed_order_days');
    }

    /**
     * Get how many days before that the customer is reminded (0 = no reminder).
     */
    public function dropOffReminderDaysBefore(): int
    {
        // Never on or after the day the order expires.
        return max(0, min($this->integer('drop_off_reminder_days_before'), $this->unclaimedOrderDays() - 1));
    }

    /**
     * Get how many failed delivery attempts a parcel may have before it must be returned.
     */
    public function maxFailedAttempts(): int
    {
        return $this->integer('max_failed_attempts');
    }

    /**
     * Get every setting with its current value.
     *
     * @return array{unclaimed_order_days: int, drop_off_reminder_days_before: int, max_failed_attempts: int}
     */
    public function all(): array
    {
        return [
            'unclaimed_order_days' => $this->unclaimedOrderDays(),
            'drop_off_reminder_days_before' => $this->dropOffReminderDaysBefore(),
            'max_failed_attempts' => $this->maxFailedAttempts(),
        ];
    }

    /**
     * Put the saved values in the cache again, after a save. Writing them,
     * rather than only forgetting the key, means a request that read the
     * table just before the save cannot leave the old values cached.
     */
    public function refresh(): void
    {
        Cache::put(self::CACHE_KEY, $this->load(), self::CACHE_SECONDS);

        $this->saved = null;
    }

    /**
     * Get a saved whole number, or its default from config/kotak.php.
     */
    private function integer(string $key): int
    {
        $value = $this->saved()[$key] ?? null;

        return is_int($value) ? $value : config()->integer("kotak.{$key}");
    }

    /**
     * Get the saved values, from memory, the cache or (once) the database.
     *
     * @return array<string, mixed>
     */
    private function saved(): array
    {
        if ($this->saved !== null) {
            return $this->saved;
        }

        $saved = Cache::get(self::CACHE_KEY);

        if (! is_array($saved)) {
            $saved = $this->load();

            // add() never replaces the values a save has just put in the cache.
            Cache::add(self::CACHE_KEY, $saved, self::CACHE_SECONDS);
        }

        return $this->saved = $saved;
    }

    /**
     * Read every saved value from the database, by key, in the order of
     * LIMITS: databases return rows in different orders, and the cached
     * array should be the same on all of them.
     *
     * @return array<string, mixed>
     */
    private function load(): array
    {
        $saved = Setting::query()->get()
            ->mapWithKeys(fn (Setting $setting): array => [$setting->key => $setting->value]);

        return collect(array_keys(self::LIMITS))
            ->filter(fn (string $key): bool => $saved->has($key))
            ->mapWithKeys(fn (string $key): array => [$key => $saved->get($key)])
            ->all();
    }
}
