<?php

namespace Tests\Feature\RateCards;

use App\Actions\RateCards\DeleteRateCardDraft;
use App\Actions\RateCards\PublishRateCard;
use App\Actions\RateCards\UpdateRateCardDraft;
use App\Actions\RateCards\WithdrawRateCard;
use App\Enums\MalaysianState;
use App\Enums\RateCardStatus;
use App\Models\Order;
use App\Models\RateCard;
use App\Models\RateCardZone;
use App\Models\User;
use App\Support\RateCards;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesRateCards;
use Tests\TestCase;

/**
 * The checks inside the rate card actions, made under the card's lock. The
 * pages only offer what a card's state allows, so these are what stop an
 * action on a page gone stale, or two admins acting at once.
 */
class RateCardActionsTest extends TestCase
{
    use CreatesRateCards;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_published_rates_cannot_be_saved_published_again_or_deleted()
    {
        $card = $this->zoneRates();

        $this->assertRefused('card', 'Published rates cannot be changed. Copy them into a new draft instead.', fn () => app(UpdateRateCardDraft::class)->handle($card, $this->draft()));
        $this->assertRefused('card', 'These rates are already published.', fn () => app(PublishRateCard::class)->handle($card, $this->admin));
        $this->assertRefused('card', 'Only drafts can be deleted.', fn () => app(DeleteRateCardDraft::class)->handle($card));

        $this->assertSame('Zone rates', $card->refresh()->name);
        $this->assertSame(3, $card->zones()->count());
    }

    public function test_the_card_is_read_again_so_a_stale_copy_is_refused()
    {
        $draft = RateCard::factory()->flat()->create();
        $stale = RateCard::query()->findOrFail($draft->id);

        app(PublishRateCard::class)->handle($draft, $this->admin);

        // The copy still says draft, but the card was published meanwhile.
        $this->assertTrue($stale->isDraft());
        $this->assertRefused('card', 'These rates are already published.', fn () => app(PublishRateCard::class)->handle($stale, $this->admin));
        $this->assertRefused('card', 'Published rates cannot be changed. Copy them into a new draft instead.', fn () => app(UpdateRateCardDraft::class)->handle($stale, $this->draft()));
    }

    public function test_only_scheduled_rates_are_withdrawn()
    {
        $current = $this->zoneRates();
        $draft = RateCard::factory()->flat()->create();
        $scheduled = RateCard::factory()->flat()->scheduled(now()->addMinutes(5))->create();

        $message = 'Only scheduled rates can be withdrawn, before they take effect.';
        $this->assertRefused('card', $message, fn () => app(WithdrawRateCard::class)->handle($current));
        $this->assertRefused('card', $message, fn () => app(WithdrawRateCard::class)->handle($draft));

        // Once its time has come, a scheduled card is in effect for good.
        $this->travel(5)->minutes();
        $this->assertRefused('card', $message, fn () => app(WithdrawRateCard::class)->handle($scheduled));
        $this->assertFalse($scheduled->refresh()->isDraft());
    }

    public function test_the_time_must_be_from_this_minute_on_and_within_two_years()
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:15:30', 'Asia/Kuala_Lumpur'));
        $draft = $this->completeDraft();

        $this->assertRefused('effective_at', 'Choose a time from now on.', fn () => app(PublishRateCard::class)->handle($draft, $this->admin, now()->subMinute()));
        $this->assertRefused('effective_at', 'Choose a date within the next two years.', fn () => app(PublishRateCard::class)->handle($draft, $this->admin, now()->addYears(2)->addMinute()));
        $this->assertTrue($draft->refresh()->isDraft());

        // Earlier in this minute means straight away.
        app(PublishRateCard::class)->handle($draft, $this->admin, now()->startOfMinute());

        $this->assertTrue($draft->refresh()->effective_from?->equalTo(now()));
        $this->assertSame($draft->id, app(RateCards::class)->current()->id);
    }

    public function test_two_rates_never_take_effect_at_the_same_moment()
    {
        $midnight = CarbonImmutable::now('Asia/Kuala_Lumpur')->addDay()->startOfDay();
        $first = $this->completeDraft();
        $second = $this->completeDraft();

        app(PublishRateCard::class)->handle($first, $this->admin, $midnight);

        $this->assertRefused(
            'effective_at',
            'Other rates take effect at that moment. Withdraw them first or choose another time.',
            fn () => app(PublishRateCard::class)->handle($second, $this->admin, $midnight),
        );
        $this->assertTrue($second->refresh()->isDraft());
        $this->assertSame($first->id, app(RateCards::class)->upcoming()?->id);

        // A minute later is fine.
        app(PublishRateCard::class)->handle($second, $this->admin, $midnight->addMinute());
        $this->assertSame($first->id, app(RateCards::class)->upcoming()?->id);
        $this->assertSame($second->id, app(RateCards::class)->current($midnight->addMinute())->id);
    }

    public function test_rates_that_priced_an_order_are_kept_as_they_are()
    {
        // Withdrawn just as it took effect, after an order was priced with it.
        $card = RateCard::factory()->flat(firstKgSen: 900)->scheduled(now()->addMinute())->create();
        Order::factory()->create(['estimated_rate_card_id' => $card->id]);

        $message = 'These rates priced an order, so they are kept.';
        $this->assertRefused('card', $message, fn () => app(WithdrawRateCard::class)->handle($card));

        $card->forceFill(['status' => RateCardStatus::Draft, 'effective_from' => null])->save();

        $this->assertRefused('card', $message, fn () => app(DeleteRateCardDraft::class)->handle($card));
        $this->assertRefused('card', "{$message} Copy them into a new draft instead.", fn () => app(UpdateRateCardDraft::class)->handle($card, $this->draft()));
        $this->assertModelExists($card);
        $this->assertSame(900, $card->routes()->sole()->bands()->sole()->price_sen);
    }

    public function test_a_final_price_keeps_its_card_too()
    {
        $card = RateCard::factory()->flat()->create();
        Order::factory()->droppedOff()->create(['final_rate_card_id' => $card->id]);

        $this->assertRefused('card', 'These rates priced an order, so they are kept.', fn () => app(DeleteRateCardDraft::class)->handle($card));
    }

    public function test_long_and_accented_zone_names_make_codes_that_fit()
    {
        $draft = RateCard::factory()->create();
        $data = $this->draft();
        $data['zones'][0]['name'] = str_repeat('Щ', 60);
        $data['zones'][1]['name'] = 'Sabah & Lábuan';

        app(UpdateRateCardDraft::class)->handle($draft, $data);

        $codes = $draft->zones()->pluck('code')->all();
        $this->assertSame(64, strlen($codes[0]));
        $this->assertSame('sabah-labuan', $codes[1]);

        // Cut where a word ends, the code drops the dangling hyphen.
        $this->assertSame(63, strlen(RateCardZone::codeFor('abc'.str_repeat('@', 57))));
        $this->assertSame('', RateCardZone::codeFor('东马'));
    }

    /**
     * Run the action and check it was refused with the message on the field.
     *
     * @param  callable(): mixed  $action
     */
    private function assertRefused(string $field, string $message, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected the action to be refused: {$message}");
        } catch (ValidationException $e) {
            $this->assertSame([$field => [$message]], $e->errors());
        }
    }

    /**
     * A complete draft, ready to publish.
     */
    private function completeDraft(): RateCard
    {
        return RateCard::factory()->withRates(self::zones(), self::routes())->create();
    }

    /**
     * A draft as UpdateRateCardRequest::draft() gives it: two zones, one route.
     *
     * @return array{name: string, volumetric_divisor: int, notes: string|null, zones: list<array{name: string, states: list<string>}>, routes: list<array{origin: int, destination: int, extra_kg_sen: int|null, bands: list<array{max_weight_g: int, price_sen: int}>}>}
     */
    private function draft(): array
    {
        $east = ['Sabah', 'Sarawak', 'Labuan'];

        return [
            'name' => 'Changed',
            'volumetric_divisor' => 5000,
            'notes' => null,
            'zones' => [
                ['name' => 'West', 'states' => array_values(array_diff(array_column(MalaysianState::cases(), 'value'), $east))],
                ['name' => 'East', 'states' => $east],
            ],
            'routes' => [
                ['origin' => 0, 'destination' => 0, 'extra_kg_sen' => 200, 'bands' => [['max_weight_g' => 1000, 'price_sen' => 800]]],
            ],
        ];
    }
}
