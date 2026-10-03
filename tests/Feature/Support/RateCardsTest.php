<?php

namespace Tests\Feature\Support;

use App\Actions\RateCards\CreateRateCardDraft;
use App\Actions\RateCards\PublishRateCard;
use App\Actions\RateCards\WithdrawRateCard;
use App\Enums\MalaysianState;
use App\Enums\RateCardStatus;
use App\Models\RateCard;
use App\Models\User;
use App\Support\RateCards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Tests\Concerns\CreatesRateCards;
use Tests\TestCase;

class RateCardsTest extends TestCase
{
    use CreatesRateCards;
    use RefreshDatabase;

    public function test_a_new_installation_starts_with_the_standard_rates_in_effect()
    {
        $current = app(RateCards::class)->current();

        $this->assertSame('Standard rates', $current->name);
        $this->assertSame(5000, $current->divisor);
        $this->assertCount(1, $current->zones);
        $this->assertSame(array_column(MalaysianState::cases(), 'value'), $current->zones[0]['states']);
        $this->assertSame([['from' => 'malaysia', 'to' => 'malaysia', 'extraKgSen' => 200, 'bands' => [['maxWeightG' => 1000, 'priceSen' => 800]]]], $current->routes);
        $this->assertNull(app(RateCards::class)->upcoming());
    }

    public function test_the_card_that_took_effect_last_is_current()
    {
        // The migration's Standard rates took effect a minute before the test began.
        $standard = app(RateCards::class)->current();
        $zoned = $this->zoneRates(now());
        $scheduled = $this->zoneRates(now()->addDay(), 'Next month');

        $rateCards = app(RateCards::class);

        $this->assertSame($zoned->id, $rateCards->current()->id);
        $this->assertSame($standard->id, $rateCards->current(now()->subSeconds(30))->id);
        $this->assertSame($scheduled->id, $rateCards->current(now()->addDays(2))->id);
        $this->assertSame($scheduled->id, $rateCards->upcoming()?->id);
        $this->assertSame('Next month', $rateCards->upcoming()?->name);
    }

    public function test_a_scheduled_card_takes_effect_at_its_time_without_touching_the_cache()
    {
        $scheduled = $this->zoneRates(now()->addMinutes(30));
        $cached = Cache::get(RateCards::CACHE_KEY);

        $this->travel(29)->minutes();
        $this->app->forgetScopedInstances();
        $this->assertNotSame($scheduled->id, app(RateCards::class)->current()->id);

        $this->travel(1)->minutes();
        $this->app->forgetScopedInstances();
        $this->assertSame($scheduled->id, app(RateCards::class)->current()->id);
        $this->assertNull(app(RateCards::class)->upcoming());
        $this->assertSame($cached, Cache::get(RateCards::CACHE_KEY));
    }

    public function test_the_published_cards_are_read_once_and_cached()
    {
        $this->zoneRates();
        Cache::forget(RateCards::CACHE_KEY);
        $this->app->forgetScopedInstances();

        $queries = $this->countQueries(function () {
            app(RateCards::class)->current();
            app(RateCards::class)->upcoming();
            app(RateCards::class)->published();
        });

        // The cards, then their zones, routes and bands.
        $this->assertSame(4, $queries);
        $this->assertCount(2, Cache::get(RateCards::CACHE_KEY));

        // A fresh instance (the next request) reads the cache, not the database.
        $this->app->forgetScopedInstances();
        $this->assertSame(0, $this->countQueries(fn () => app(RateCards::class)->current()));
    }

    public function test_drafts_are_never_cached_or_used()
    {
        $draft = RateCard::factory()->flat(firstKgSen: 100)->create();

        $this->assertNull(app(RateCards::class)->find($draft->id));
        $this->assertNotSame($draft->id, app(RateCards::class)->current()->id);
        $this->assertCount(1, Cache::get(RateCards::CACHE_KEY));
    }

    public function test_publishing_and_withdrawing_rewrite_the_cache()
    {
        $admin = User::factory()->admin()->create();
        $rateCards = app(RateCards::class);
        $standard = $rateCards->current();

        $draft = app(CreateRateCardDraft::class)->handle($admin, RateCard::query()->findOrFail($standard->id));
        app(PublishRateCard::class)->handle($draft, $admin, now()->addDay());

        $this->assertSame($draft->id, $rateCards->upcoming()?->id);
        $this->assertSame([$standard->id, $draft->id], array_column(Cache::get(RateCards::CACHE_KEY), 'id'));

        app(WithdrawRateCard::class)->handle($draft);

        $this->assertNull($rateCards->upcoming());
        $this->assertSame([$standard->id], array_column(Cache::get(RateCards::CACHE_KEY), 'id'));
    }

    public function test_a_change_made_behind_the_cache_is_seen_within_a_day()
    {
        $rateCards = app(RateCards::class);
        $standard = $rateCards->current();

        RateCard::query()->whereKey($standard->id)->update(['name' => 'Renamed']);
        $this->app->forgetScopedInstances();
        $this->assertSame('Standard rates', app(RateCards::class)->current()->name);

        $this->travel(RateCards::CACHE_SECONDS + 1)->seconds();
        $this->app->forgetScopedInstances();
        $this->assertSame('Renamed', app(RateCards::class)->current()->name);
    }

    public function test_a_read_that_started_before_a_publish_cannot_cache_the_old_list()
    {
        $admin = User::factory()->admin()->create();
        $draft = RateCard::factory()->flat(firstKgSen: 900)->create();
        Cache::forget(RateCards::CACHE_KEY);

        // Another request reads the table; the publish commits before it caches what it read.
        $published = false;
        RateCard::retrieved(function () use ($admin, $draft, &$published) {
            if (! $published) {
                $published = true;
                app(PublishRateCard::class)->handle($draft, $admin);
            }
        });

        $this->assertNotSame($draft->id, (new RateCards)->current()->id);

        $this->assertTrue($published);
        $this->assertSame($draft->id, Cache::get(RateCards::CACHE_KEY)[1]['id']);
        $this->app->forgetScopedInstances();
        $this->assertSame($draft->id, app(RateCards::class)->current()->id);
    }

    public function test_refreshes_take_turns_and_read_the_table_while_holding_the_lock()
    {
        $draft = RateCard::factory()->flat(firstKgSen: 900)->create();
        $lock = RateCards::CACHE_KEY.':refresh';
        $heldWhileReading = null;

        RateCard::retrieved(function () use ($lock, &$heldWhileReading) {
            // Another refresh cannot start while this one reads the cards.
            $heldWhileReading ??= ! Cache::lock($lock, 10)->get();
        });

        $draft->forceFill(['status' => RateCardStatus::Published, 'effective_from' => now()])->save();
        app(RateCards::class)->refresh();

        $this->assertTrue($heldWhileReading);
        $this->assertTrue(Cache::lock($lock, 10)->get(), 'The lock is let go afterwards.');
        $this->assertSame($draft->id, Cache::get(RateCards::CACHE_KEY)[1]['id']);
    }

    public function test_a_refresh_still_writes_the_list_when_the_lock_is_not_free()
    {
        $draft = RateCard::factory()->flat(firstKgSen: 900)->create();
        Cache::lock(RateCards::CACHE_KEY.':refresh', 10)->get();
        $this->freezeTime();

        // Waiting for the lock times out after 5 seconds.
        Sleep::fake(syncWithCarbon: true);

        $draft->forceFill(['status' => RateCardStatus::Published, 'effective_from' => now()])->save();
        app(RateCards::class)->refresh();

        $this->assertSame($draft->id, Cache::get(RateCards::CACHE_KEY)[1]['id']);
    }

    public function test_before_the_first_card_takes_effect_the_first_card_applies()
    {
        // Only seen when the clock is moved back, as tests and the demo seeder do.
        $this->travelTo(now()->subYear());

        $this->assertSame('Standard rates', app(RateCards::class)->current()->name);
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
