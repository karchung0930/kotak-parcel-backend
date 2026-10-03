<?php

namespace Tests\Feature\Admin;

use App\Enums\MalaysianState;
use App\Enums\RateCardStatus;
use App\Enums\Role;
use App\Models\Order;
use App\Models\RateCard;
use App\Models\RateCardBand;
use App\Models\User;
use App\Support\RateCards;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesRateCards;
use Tests\TestCase;

class RateCardsTest extends TestCase
{
    use CreatesRateCards;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Farah Aziz']);
    }

    public function test_admins_see_every_version_drafts_first()
    {
        $standard = RateCard::query()->sole();
        $current = $this->zoneRates(name: 'Zone rates');
        $scheduled = $this->zoneRates(now()->addWeek(), 'Next rates');
        $scheduled->forceFill(['published_by' => $this->admin->id])->save();
        $draft = RateCard::factory()->create(['name' => 'Festive rates']);

        $this->actingAs($this->admin)
            ->get(route('admin.rates.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/rates/Index')
                ->has('rateCards', 4)
                ->where('rateCards.0.id', $draft->id)
                ->where('rateCards.0.phase', ['value' => 'draft', 'label' => 'Draft'])
                ->where('rateCards.0.effective_from', null)
                ->where('rateCards.1.id', $scheduled->id)
                ->where('rateCards.1.phase.value', 'scheduled')
                ->where('rateCards.1.published_by', ['id' => $this->admin->id, 'name' => 'Farah Aziz'])
                ->where('rateCards.2.id', $current->id)
                ->where('rateCards.2.phase.value', 'current')
                ->where('rateCards.3.id', $standard->id)
                ->where('rateCards.3.phase.value', 'past')
                // The migration published the first card, so nobody did.
                ->where('rateCards.3.published_by', null)
                ->missing('rateCards.0.zones'));
    }

    public function test_a_version_shows_its_zones_and_each_route_s_bands()
    {
        $card = $this->zoneRates();

        $this->actingAs($this->admin)
            ->get(route('admin.rates.show', $card))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/rates/Show')
                ->where('rateCard.phase.value', 'current')
                ->has('rateCard.zones', 3)
                ->where('rateCard.zones.1.name', 'Sabah & Labuan')
                ->where('rateCard.zones.1.states', ['Sabah', 'Labuan'])
                ->has('rateCard.routes', 9)
                ->where('rateCard.routes.1.origin_zone_id', $card->zones[0]->id)
                ->where('rateCard.routes.1.destination_zone_id', $card->zones[1]->id)
                ->where('rateCard.routes.1.extra_kg_sen', 500)
                ->where('rateCard.routes.1.bands.0', ['max_weight_g' => 500, 'price_sen' => 900])
                ->has('states', count(MalaysianState::cases()))
                ->where('problems', [])
                ->where('can', ['update' => false, 'publish' => false, 'withdraw' => false, 'delete' => false]));
    }

    public function test_a_draft_shows_what_stops_it_from_being_published()
    {
        $draft = RateCard::factory()->withRates(
            [['code' => 'west', 'name' => 'West', 'states' => ['Selangor', 'Johor']]],
            [['from' => 'west', 'to' => 'west', 'extraKgSen' => null, 'bands' => [['maxWeightG' => 1000, 'priceSen' => 800]]]],
        )->create();

        $this->actingAs($this->admin)
            ->get(route('admin.rates.show', $draft))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rateCard.phase.value', 'draft')
                ->where('problems', [
                    'These states are not in any zone: Kedah, Kelantan, Melaka, Negeri Sembilan, Pahang, Perak, Perlis, Pulau Pinang, Sabah, Sarawak, Terengganu, W.P. Kuala Lumpur, W.P. Labuan, W.P. Putrajaya.',
                    'Within West: set the price per extra kg.',
                ])
                ->where('can', ['update' => true, 'publish' => true, 'withdraw' => false, 'delete' => true]));
    }

    public function test_admins_start_a_draft_from_any_version()
    {
        $card = $this->zoneRates();

        $response = $this->actingAs($this->admin)->post(route('admin.rates.store'), ['source_id' => $card->id]);

        $draft = RateCard::query()->latest('id')->firstOrFail();
        $response->assertRedirect(route('admin.rates.edit', $draft))
            ->assertInertiaFlash('toast.type', 'success');

        $this->assertSame('Copy of Zone rates', $draft->name);
        $this->assertSame(RateCardStatus::Draft, $draft->status);
        $this->assertNull($draft->effective_from);
        $this->assertSame($this->admin->id, $draft->created_by);
        $this->assertSame(
            $card->zones->map->only(['code', 'name', 'states'])->all(),
            $draft->zones->map->only(['code', 'name', 'states'])->all(),
        );
        $this->assertSame(9, $draft->routes()->count());
        $this->assertSame(
            RateCardBand::query()->whereIn('rate_card_route_id', $card->routes->pluck('id'))->orderBy('id')->get(['max_weight_g', 'price_sen'])->toArray(),
            RateCardBand::query()->whereIn('rate_card_route_id', $draft->routes()->pluck('id'))->orderBy('id')->get(['max_weight_g', 'price_sen'])->toArray(),
        );
        // The copy's routes join its own zones.
        $this->assertEqualsCanonicalizing($draft->zones()->pluck('id')->all(), $draft->routes()->pluck('origin_zone_id')->unique()->values()->all());
    }

    public function test_admins_start_a_blank_draft()
    {
        $this->actingAs($this->admin)->post(route('admin.rates.store'), ['source_id' => null])->assertSessionHasNoErrors();

        $draft = RateCard::query()->latest('id')->firstOrFail();

        $this->assertSame('New rates', $draft->name);
        $this->assertSame(5000, $draft->volumetric_divisor);
        $this->assertSame(0, $draft->zones()->count());
    }

    public function test_the_editor_opens_for_drafts_only()
    {
        $draft = RateCard::factory()->flat()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.rates.edit', $draft))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/rates/Edit')
                ->where('rateCard.id', $draft->id)
                ->has('rateCard.zones', 1)
                ->has('rateCard.routes.0.bands', 1)
                ->where('maxWeightG', 30000)
                ->has('states', count(MalaysianState::cases())));

        // Published rates open on their own page, which says why.
        $published = RateCard::query()->published()->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('admin.rates.edit', $published))
            ->assertRedirect(route('admin.rates.show', $published))
            ->assertSessionHasErrors(['card' => 'Published rates cannot be changed. Copy them into a new draft instead.']);
    }

    public function test_admins_save_a_draft()
    {
        $draft = RateCard::factory()->flat()->create();

        $this->actingAs($this->admin)
            ->put(route('admin.rates.update', $draft), $this->draftInput())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.rates.show', $draft))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Draft saved.']);

        $draft->refresh()->load(['zones', 'routes.bands']);

        $this->assertSame('East and West', $draft->name);
        $this->assertSame(6000, $draft->volumetric_divisor);
        $this->assertSame('Two zones.', $draft->notes);
        $this->assertSame(['peninsular-malaysia', 'sabah-sarawak-labuan'], $draft->zones->pluck('code')->all());
        $this->assertSame(['Sabah', 'Sarawak', 'Labuan'], $draft->zones[1]->states);
        $this->assertCount(4, $draft->routes);

        $westToEast = $draft->routes[1];
        $this->assertSame([$draft->zones[0]->id, $draft->zones[1]->id], [$westToEast->origin_zone_id, $westToEast->destination_zone_id]);
        $this->assertSame(500, $westToEast->extra_kg_sen);
        $this->assertSame([[500, 900], [2000, 1700]], $westToEast->bands->map(fn (RateCardBand $band) => [$band->max_weight_g, $band->price_sen])->all());

        // The old zone, route and band were replaced: four bands for the
        // draft, and the Standard rates' one.
        $this->assertSame(4, RateCardBand::query()->whereIn('rate_card_route_id', $draft->routes->pluck('id'))->count());
        $this->assertSame(5, RateCardBand::query()->count());
    }

    public function test_a_draft_can_be_saved_before_it_is_complete()
    {
        $draft = RateCard::factory()->create();

        $this->actingAs($this->admin)
            ->put(route('admin.rates.update', $draft), [
                'name' => 'Work in progress',
                'volumetric_divisor' => 5000,
                'notes' => null,
                'zones' => [['name' => 'Klang Valley', 'states' => ['Selangor', 'Kuala Lumpur']]],
                'routes' => [],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(['Selangor', 'Kuala Lumpur'], $draft->zones()->sole()->states);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidDrafts(): array
    {
        return [
            'no name' => [['name' => ''], 'name'],
            'divisor of zero' => [['volumetric_divisor' => 0], 'volumetric_divisor'],
            'divisor too small' => [['volumetric_divisor' => 999], 'volumetric_divisor'],
            'divisor too large' => [['volumetric_divisor' => 10001], 'volumetric_divisor'],
            'fractional divisor' => [['volumetric_divisor' => 5000.5], 'volumetric_divisor'],
            'unnamed zone' => [['zones.0.name' => ''], 'zones.0.name'],
            'zone name without letters' => [['zones.0.name' => '---'], 'zones.0.name'],
            'zone name in Chinese only' => [['zones.1.name' => '东马'], 'zones.1.name'],
            'zone name in Thai only' => [['zones.1.name' => 'ซาบาห์'], 'zones.1.name'],
            'zone name too long' => [['zones.1.name' => str_repeat('a', 61)], 'zones.1.name'],
            'state listed over and over' => [['zones.1.states' => array_fill(0, 17, 'Sabah')], 'zones.1.states'],
            'unknown state' => [['zones.0.states' => ['Selangor', 'Singapore']], 'zones.0.states.1'],
            'state in two zones' => [['zones.1.states' => ['Sabah', 'Selangor']], 'zones.1.states'],
            'two zones with one name' => [['zones.1.name' => 'Peninsular  Malaysia'], 'zones.1.name'],
            'route to a missing zone' => [['routes.0.destination' => 5], 'routes.0.destination'],
            'route listed twice' => [['routes.1.destination' => 0], 'routes.1'],
            'band over the weight limit' => [['routes.1.bands.1.max_weight_g' => 30001], 'routes.1.bands.1.max_weight_g'],
            'band of zero grams' => [['routes.1.bands.0.max_weight_g' => 0], 'routes.1.bands.0.max_weight_g'],
            'band weight twice' => [['routes.1.bands.1.max_weight_g' => 500], 'routes.1.bands.1.max_weight_g'],
            'negative price' => [['routes.1.bands.0.price_sen' => -1], 'routes.1.bands.0.price_sen'],
            'price in ringgit' => [['routes.1.bands.0.price_sen' => 9.5], 'routes.1.bands.0.price_sen'],
            'negative price per extra kg' => [['routes.1.extra_kg_sen' => -100], 'routes.1.extra_kg_sen'],
            'unexpected band field' => [['routes.1.bands.0.colour' => 'red'], 'routes.1.bands.0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    #[DataProvider('invalidDrafts')]
    public function test_invalid_drafts_are_rejected(array $changes, string $field)
    {
        $draft = RateCard::factory()->flat()->create(['name' => 'Before']);
        $input = $this->draftInput();

        foreach ($changes as $key => $value) {
            data_set($input, $key, $value);
        }

        $this->actingAs($this->admin)
            ->from(route('admin.rates.edit', $draft))
            ->put(route('admin.rates.update', $draft), $input)
            ->assertRedirect(route('admin.rates.edit', $draft))
            ->assertSessionHasErrors($field);

        $this->assertSame('Before', $draft->refresh()->name);
        $this->assertSame(1, $draft->zones()->count());
    }

    public function test_more_routes_than_pairs_of_zones_are_rejected()
    {
        $draft = RateCard::factory()->create();
        $input = $this->draftInput();
        $input['routes'] = array_fill(0, 16 * 16 + 1, ['origin' => 0, 'destination' => 0, 'extra_kg_sen' => null, 'bands' => []]);

        $this->actingAs($this->admin)
            ->put(route('admin.rates.update', $draft), $input)
            ->assertSessionHasErrors(['routes' => 'List each route once.']);

        $this->assertSame(0, $draft->routes()->count());
    }

    public function test_zone_names_need_latin_letters_or_numbers_for_their_code()
    {
        $draft = RateCard::factory()->create();
        $input = $this->draftInput();
        data_set($input, 'zones.0.name', '西马');
        data_set($input, 'zones.1.name', '东马');

        // Each is told what to change, not that the two names clash.
        $this->actingAs($this->admin)
            ->put(route('admin.rates.update', $draft), $input)
            ->assertSessionHasErrors([
                'zones.0.name' => 'Use Latin letters or numbers in the name.',
                'zones.1.name' => 'Use Latin letters or numbers in the name.',
            ]);

        $this->assertSame(0, $draft->zones()->count());

        // Other scripts are fine beside Latin letters.
        data_set($input, 'zones.0.name', 'West Malaysia 西马');
        data_set($input, 'zones.1.name', 'East Malaysia 东马');

        $this->actingAs($this->admin)->put(route('admin.rates.update', $draft), $input)->assertSessionHasNoErrors();

        $this->assertSame(['west-malaysia', 'east-malaysia'], $draft->zones()->pluck('code')->all());
    }

    public function test_long_zone_names_make_codes_that_fit_the_column()
    {
        $draft = RateCard::factory()->create();
        $input = $this->draftInput();
        // 60 characters, each written as several Latin letters in the code.
        data_set($input, 'zones.0.name', str_repeat('Щ', 60));
        data_set($input, 'zones.1.name', 'Zone '.str_repeat('@ ', 27));

        $this->actingAs($this->admin)->put(route('admin.rates.update', $draft), $input)->assertSessionHasNoErrors();

        $codes = $draft->zones()->pluck('code')->all();
        $this->assertSame([64, 64], array_map(strlen(...), $codes));
        $this->assertStringStartsWith('zone-at-at-', $codes[1]);
        $this->assertStringEndsNotWith('-', $codes[1]);
    }

    public function test_names_that_make_the_same_code_are_rejected()
    {
        $draft = RateCard::factory()->create();
        $input = $this->draftInput();
        data_set($input, 'zones.0.name', str_repeat('Щ', 60));
        data_set($input, 'zones.1.name', str_repeat('Щ', 59).'Ж');

        // Both codes are cut to the same 64 characters.
        $this->actingAs($this->admin)
            ->put(route('admin.rates.update', $draft), $input)
            ->assertSessionHasErrors(['zones.1.name' => 'Another zone already has this name.']);
    }

    public function test_a_state_ticked_twice_in_one_zone_is_kept_once()
    {
        $draft = RateCard::factory()->create();
        $input = $this->draftInput();
        data_set($input, 'zones.1.states', ['Sabah', 'Sarawak', 'Labuan', 'Sabah']);

        $this->actingAs($this->admin)->put(route('admin.rates.update', $draft), $input)->assertSessionHasNoErrors();

        $this->assertSame(['Sabah', 'Sarawak', 'Labuan'], $draft->zones()->get()[1]->states);
    }

    public function test_validation_messages_are_in_plain_english()
    {
        $draft = RateCard::factory()->create();
        $input = $this->draftInput();
        data_set($input, 'zones.1.states', ['Sabah', 'Selangor']);

        $this->actingAs($this->admin)
            ->put(route('admin.rates.update', $draft), $input)
            ->assertSessionHasErrors(['zones.1.states' => 'Selangor is already in Peninsular Malaysia. A state can only be in one zone.']);

        $input = $this->draftInput();
        data_set($input, 'routes.1.bands.1.max_weight_g', 31000);
        data_set($input, 'routes.1.bands.0.price_sen', -5);

        $this->actingAs($this->admin)
            ->put(route('admin.rates.update', $draft), $input)
            ->assertSessionHasErrors([
                'routes.1.bands.1.max_weight_g' => 'Enter a weight above 0 and up to 30 kg.',
                'routes.1.bands.0.price_sen' => 'Enter a price from RM 0.00 to RM 10,000.00.',
            ]);
    }

    public function test_published_rates_never_change()
    {
        $card = $this->zoneRates();
        $page = route('admin.rates.show', $card);

        $this->actingAs($this->admin)->from($page)->put(route('admin.rates.update', $card), $this->draftInput())
            ->assertRedirect($page)
            ->assertSessionHasErrors(['card' => 'Published rates cannot be changed. Copy them into a new draft instead.']);
        $this->actingAs($this->admin)->from($page)->delete(route('admin.rates.destroy', $card))
            ->assertRedirect($page)
            ->assertSessionHasErrors(['card' => 'Only drafts can be deleted.']);
        $this->actingAs($this->admin)->from($page)->post(route('admin.rates.publish', $card), ['when' => 'now'])
            ->assertRedirect($page)
            ->assertSessionHasErrors(['card' => 'These rates are already published.']);

        $this->assertSame('Zone rates', $card->refresh()->name);
        $this->assertSame(3, $card->zones()->count());
        $this->assertSame(9, $card->routes()->count());
    }

    public function test_saving_a_draft_published_meanwhile_says_why_it_was_not_saved()
    {
        $draft = $this->completeDraft();
        $editor = route('admin.rates.edit', $draft);

        // Another admin publishes it while this one is still editing.
        $this->actingAs($this->admin)->post(route('admin.rates.publish', $draft), ['when' => 'now']);

        $this->actingAs($this->admin)->from($editor)->put(route('admin.rates.update', $draft), $this->draftInput())
            ->assertRedirect($editor)
            ->assertSessionHasErrors(['card' => 'Published rates cannot be changed. Copy them into a new draft instead.']);

        $this->assertSame('New zone rates', $draft->refresh()->name);
    }

    public function test_publishing_a_draft_twice_says_it_is_already_published()
    {
        $draft = $this->completeDraft();
        $page = route('admin.rates.show', $draft);

        $this->actingAs($this->admin)->from($page)->post(route('admin.rates.publish', $draft), ['when' => 'now'])
            ->assertSessionHasNoErrors();
        $publishedAt = $draft->refresh()->effective_from;

        $this->travel(1)->minutes();

        $this->actingAs($this->admin)->from($page)->post(route('admin.rates.publish', $draft), ['when' => 'now'])
            ->assertRedirect($page)
            ->assertSessionHasErrors(['card' => 'These rates are already published.']);

        $this->assertTrue($draft->refresh()->effective_from?->equalTo($publishedAt));
    }

    public function test_admins_publish_a_draft_straight_away()
    {
        $this->freezeSecond();
        $draft = $this->completeDraft();

        $this->actingAs($this->admin)
            ->post(route('admin.rates.publish', $draft), ['when' => 'now'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.rates.show', $draft))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Rates published. New orders are priced with them from now on.']);

        $draft->refresh();

        $this->assertSame(RateCardStatus::Published, $draft->status);
        $this->assertSame($this->admin->id, $draft->published_by);
        $this->assertTrue($draft->effective_from?->equalTo(now()));
        $this->assertSame($draft->id, app(RateCards::class)->current()->id);
    }

    public function test_admins_schedule_a_draft_for_a_malaysian_date_and_time()
    {
        $midnight = CarbonImmutable::now('Asia/Kuala_Lumpur')->addDays(10)->startOfDay();
        $draft = $this->completeDraft();

        $this->actingAs($this->admin)
            ->post(route('admin.rates.publish', $draft), ['when' => 'scheduled', 'effective_at' => $midnight->format('Y-m-d\TH:i')])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', "Rates scheduled for {$midnight->format('j M Y')}, 00:00.");

        // Midnight in Kuala Lumpur is 16:00 the day before in UTC.
        $this->assertSame(
            $midnight->subDay()->format('Y-m-d').' 16:00:00',
            $draft->refresh()->effective_from?->utc()->toDateTimeString(),
        );
        $this->assertSame('scheduled', $draft->phase()->value);
        $this->assertNotSame($draft->id, app(RateCards::class)->current()->id);
        $this->assertSame($draft->id, app(RateCards::class)->upcoming()?->id);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidPublishing(): array
    {
        return [
            'no choice' => [[], 'when'],
            'unknown choice' => [['when' => 'later'], 'when'],
            'no date' => [['when' => 'scheduled'], 'effective_at'],
            'not a date' => [['when' => 'scheduled', 'effective_at' => 'next week'], 'effective_at'],
            'in the past' => [['when' => 'scheduled', 'effective_at' => '2026-10-20T09:59'], 'effective_at'],
            'over two years ahead' => [['when' => 'scheduled', 'effective_at' => '2028-10-20T10:01'], 'effective_at'],
            'beyond the timestamp range' => [['when' => 'scheduled', 'effective_at' => '2040-01-01T00:00'], 'effective_at'],
        ];
    }

    /**
     * @param  array<string, string>  $input
     */
    #[DataProvider('invalidPublishing')]
    public function test_publishing_needs_a_time_from_now_on(array $input, string $field)
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Kuala_Lumpur'));
        $draft = $this->completeDraft();

        $this->actingAs($this->admin)
            ->post(route('admin.rates.publish', $draft), $input)
            ->assertSessionHasErrors($field);

        $this->assertTrue($draft->refresh()->isDraft());
    }

    public function test_publishing_messages_say_which_times_are_allowed()
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Kuala_Lumpur'));
        $draft = $this->completeDraft();

        $this->actingAs($this->admin)
            ->post(route('admin.rates.publish', $draft), ['when' => 'scheduled', 'effective_at' => '2026-10-20T09:59'])
            ->assertSessionHasErrors(['effective_at' => 'Choose a time from now on.']);

        $this->actingAs($this->admin)
            ->post(route('admin.rates.publish', $draft), ['when' => 'scheduled', 'effective_at' => '2040-01-01T00:00'])
            ->assertSessionHasErrors(['effective_at' => 'Choose a date within the next two years.']);

        // Two years ahead to the minute is still allowed.
        $this->actingAs($this->admin)
            ->post(route('admin.rates.publish', $draft), ['when' => 'scheduled', 'effective_at' => '2028-10-20T10:00'])
            ->assertSessionHasNoErrors();
    }

    public function test_a_time_earlier_in_this_minute_publishes_straight_away()
    {
        // The picker offers whole minutes, the earliest being this one.
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:15:30', 'Asia/Kuala_Lumpur'));
        $draft = $this->completeDraft();

        $this->actingAs($this->admin)
            ->post(route('admin.rates.publish', $draft), ['when' => 'scheduled', 'effective_at' => '2026-10-20T10:15'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Rates published. New orders are priced with them from now on.');

        $this->assertTrue($draft->refresh()->effective_from?->equalTo(now()));
        $this->assertSame('current', $draft->phase()->value);
    }

    public function test_rates_cannot_be_scheduled_for_the_moment_other_rates_take_effect()
    {
        $midnight = CarbonImmutable::now('Asia/Kuala_Lumpur')->addDays(3)->startOfDay();
        $scheduled = $this->zoneRates($midnight, 'Next rates');
        $draft = $this->completeDraft();

        $this->actingAs($this->admin)
            ->post(route('admin.rates.publish', $draft), ['when' => 'scheduled', 'effective_at' => $midnight->format('Y-m-d\TH:i')])
            ->assertSessionHasErrors(['effective_at' => 'Other rates take effect at that moment. Withdraw them first or choose another time.']);

        $this->assertTrue($draft->refresh()->isDraft());
        $this->assertSame($scheduled->id, app(RateCards::class)->upcoming()?->id);
    }

    public function test_a_zone_without_states_cannot_be_published()
    {
        $draft = RateCard::factory()->withRates(
            [
                ['code' => 'malaysia', 'name' => 'Malaysia', 'states' => array_column(MalaysianState::cases(), 'value')],
                ['code' => 'nowhere', 'name' => 'Nowhere', 'states' => []],
            ],
            [
                ['from' => 'malaysia', 'to' => 'malaysia', 'extraKgSen' => 200, 'bands' => [['maxWeightG' => 1000, 'priceSen' => 800]]],
                ['from' => 'malaysia', 'to' => 'nowhere', 'extraKgSen' => 200, 'bands' => [['maxWeightG' => 1000, 'priceSen' => 800]]],
                ['from' => 'nowhere', 'to' => 'malaysia', 'extraKgSen' => 200, 'bands' => [['maxWeightG' => 1000, 'priceSen' => 800]]],
                ['from' => 'nowhere', 'to' => 'nowhere', 'extraKgSen' => 200, 'bands' => [['maxWeightG' => 1000, 'priceSen' => 800]]],
            ],
        )->create();

        $this->actingAs($this->admin)
            ->post(route('admin.rates.publish', $draft), ['when' => 'now'])
            ->assertSessionHasErrors(['problems.0' => 'Nowhere has no states. Add states or remove the zone.']);

        $this->assertTrue($draft->refresh()->isDraft());
        $this->assertNull(app(RateCards::class)->find($draft->id));
    }

    public function test_publishing_reports_every_problem_at_once()
    {
        $draft = RateCard::factory()->withRates(
            [
                ['code' => 'west', 'name' => 'West', 'states' => array_values(array_diff(array_column(MalaysianState::cases(), 'value'), ['Sabah', 'Sarawak', 'Labuan', 'Perlis']))],
                ['code' => 'east', 'name' => 'East', 'states' => ['Sabah', 'Sarawak', 'Labuan', 'Johor']],
            ],
            [
                ['from' => 'west', 'to' => 'west', 'extraKgSen' => 200, 'bands' => [['maxWeightG' => 1000, 'priceSen' => 800]]],
                ['from' => 'west', 'to' => 'east', 'extraKgSen' => null, 'bands' => [['maxWeightG' => 1000, 'priceSen' => 1200], ['maxWeightG' => 2000, 'priceSen' => 1100], ['maxWeightG' => 35000, 'priceSen' => 9000]]],
                ['from' => 'east', 'to' => 'east', 'extraKgSen' => 250, 'bands' => []],
            ],
        )->create();

        $this->actingAs($this->admin)
            ->post(route('admin.rates.publish', $draft), ['when' => 'now'])
            ->assertSessionHasErrors([
                'problems.0' => 'Perlis is not in any zone.',
                'problems.1' => 'Johor is in more than one zone (West, East).',
                'problems.2' => 'West → East: up to 2 kg costs less than up to 1 kg. Prices must not go down as the weight goes up.',
                'problems.3' => 'West → East: the 35 kg band is over the 30 kg limit.',
                'problems.4' => 'West → East: set the price per extra kg.',
                'problems.5' => 'East → West: add the prices.',
                'problems.6' => 'Within East: add at least one weight band.',
            ]);

        $this->assertTrue($draft->refresh()->isDraft());
    }

    public function test_a_draft_without_zones_cannot_be_published()
    {
        $draft = RateCard::factory()->create(['volumetric_divisor' => 0]);

        $this->actingAs($this->admin)
            ->post(route('admin.rates.publish', $draft), ['when' => 'now'])
            ->assertSessionHasErrors([
                'problems.0' => 'Set a volumetric divisor above 0.',
                'problems.1' => 'Add at least one zone.',
            ]);
    }

    public function test_scheduled_rates_can_be_withdrawn_before_they_take_effect()
    {
        $scheduled = $this->zoneRates(now()->addDay());

        $this->actingAs($this->admin)
            ->post(route('admin.rates.withdraw', $scheduled))
            ->assertRedirect(route('admin.rates.show', $scheduled))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Rates withdrawn. They are a draft again.']);

        $scheduled->refresh();

        $this->assertTrue($scheduled->isDraft());
        $this->assertNull($scheduled->effective_from);
        $this->assertNull($scheduled->published_at);
        $this->assertNull(app(RateCards::class)->upcoming());
        // A draft again, so it can be changed or deleted.
        $this->actingAs($this->admin)->get(route('admin.rates.edit', $scheduled))->assertOk();
    }

    public function test_rates_in_effect_cannot_be_withdrawn()
    {
        $current = $this->zoneRates();
        $draft = RateCard::factory()->create();
        $message = 'Only scheduled rates can be withdrawn, before they take effect.';

        $this->actingAs($this->admin)->post(route('admin.rates.withdraw', $current))->assertSessionHasErrors(['card' => $message]);
        $this->actingAs($this->admin)->post(route('admin.rates.withdraw', $draft))->assertSessionHasErrors(['card' => $message]);

        $this->assertSame($current->id, app(RateCards::class)->current()->id);
    }

    public function test_a_withdraw_that_comes_too_late_says_why()
    {
        $scheduled = $this->zoneRates(now()->addMinutes(5));
        $page = route('admin.rates.show', $scheduled);

        // The page was opened before the rates took effect.
        $this->actingAs($this->admin)->get($page)->assertInertia(fn (Assert $page) => $page->where('can.withdraw', true));
        $this->travel(6)->minutes();

        $this->actingAs($this->admin)->from($page)->post(route('admin.rates.withdraw', $scheduled))
            ->assertRedirect($page)
            ->assertSessionHasErrors(['card' => 'Only scheduled rates can be withdrawn, before they take effect.']);

        $this->assertSame('current', $scheduled->refresh()->phase()->value);
    }

    public function test_drafts_can_be_deleted()
    {
        $draft = RateCard::factory()->flat()->create(['name' => 'Festive rates']);

        $this->actingAs($this->admin)
            ->delete(route('admin.rates.destroy', $draft))
            ->assertRedirect(route('admin.rates.index'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Draft “Festive rates” deleted.']);

        $this->assertModelMissing($draft);
        $this->assertSame(1, RateCard::query()->count());
        $this->assertSame(1, RateCardBand::query()->count());
    }

    public function test_a_draft_that_priced_an_order_is_kept()
    {
        // Rates withdrawn just as they took effect, after pricing an order.
        $card = $this->zoneRates();
        Order::factory()->create(['estimated_rate_card_id' => $card->id]);
        $card->forceFill(['status' => RateCardStatus::Draft, 'effective_from' => null])->save();
        $page = route('admin.rates.show', $card);

        $this->actingAs($this->admin)->from($page)->delete(route('admin.rates.destroy', $card))
            ->assertRedirect($page)
            ->assertSessionHasErrors(['card' => 'These rates priced an order, so they are kept.']);

        $this->assertModelExists($card);
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function otherRoles(): array
    {
        return [
            'staff' => [Role::Staff],
            'driver' => [Role::Driver],
            'customer' => [Role::Customer],
        ];
    }

    #[DataProvider('otherRoles')]
    public function test_only_admins_manage_rates(Role $role)
    {
        $user = User::factory()->create(['role' => $role]);
        $card = RateCard::query()->sole();
        $draft = RateCard::factory()->flat()->create();
        $scheduled = RateCard::factory()->flat()->scheduled()->create();

        $this->actingAs($user)->get(route('admin.rates.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.rates.show', $card))->assertForbidden();
        $this->actingAs($user)->post(route('admin.rates.store'), ['source_id' => $card->id])->assertForbidden();
        $this->actingAs($user)->get(route('admin.rates.edit', $draft))->assertForbidden();
        $this->actingAs($user)->put(route('admin.rates.update', $draft), $this->draftInput())->assertForbidden();
        $this->actingAs($user)->post(route('admin.rates.publish', $draft), ['when' => 'now'])->assertForbidden();
        $this->actingAs($user)->delete(route('admin.rates.destroy', $draft))->assertForbidden();
        $this->actingAs($user)->post(route('admin.rates.withdraw', $scheduled))->assertForbidden();

        $this->assertSame(3, RateCard::query()->count());
        $this->assertTrue($draft->refresh()->isDraft());
        $this->assertTrue($scheduled->refresh()->isScheduled());
    }

    public function test_guests_are_sent_to_log_in()
    {
        $this->get(route('admin.rates.index'))->assertRedirect(route('login'));
    }

    /**
     * A complete draft, ready to publish: the zone rates as a draft.
     */
    private function completeDraft(): RateCard
    {
        return RateCard::factory()->withRates(self::zones(), self::routes())->create(['name' => 'New zone rates']);
    }

    /**
     * Valid editor input: two zones and their four routes, in grams and sen.
     *
     * @return array<string, mixed>
     */
    private function draftInput(): array
    {
        $east = ['Sabah', 'Sarawak', 'Labuan'];

        return [
            'name' => 'East and West',
            'volumetric_divisor' => 6000,
            'notes' => 'Two zones.',
            'zones' => [
                ['name' => 'Peninsular Malaysia', 'states' => array_values(array_diff(array_column(MalaysianState::cases(), 'value'), $east))],
                ['name' => 'Sabah, Sarawak & Labuan', 'states' => $east],
            ],
            'routes' => [
                ['origin' => 0, 'destination' => 0, 'extra_kg_sen' => 200, 'bands' => [['max_weight_g' => 1000, 'price_sen' => 800]]],
                ['origin' => 0, 'destination' => 1, 'extra_kg_sen' => 500, 'bands' => [['max_weight_g' => 500, 'price_sen' => 900], ['max_weight_g' => 2000, 'price_sen' => 1700]]],
                ['origin' => 1, 'destination' => 0, 'extra_kg_sen' => 550, 'bands' => [['max_weight_g' => 2000, 'price_sen' => 1800]]],
                ['origin' => 1, 'destination' => 1, 'extra_kg_sen' => null, 'bands' => []],
            ],
        ];
    }
}
