<?php

namespace Tests\Feature\Public;

use App\Enums\MalaysianState;
use App\Models\Branch;
use App\Support\PriceCalculator;
use App\Support\RateCards;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesRateCards;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use CreatesRateCards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pages are checked through their Inertia props, not the built assets.
        $this->withoutVite();
    }

    public function test_the_home_page_offers_active_branches_and_the_pricing_rules()
    {
        $branch = Branch::factory()->create(['name' => 'Petaling Jaya - SS2', 'latitude' => 3.1180000, 'longitude' => 101.6240000]);
        Branch::factory()->inactive()->create();

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->has('branches', 1)
                ->where('branches.0.id', $branch->id)
                ->where('branches.0.name', 'Petaling Jaya - SS2')
                // Coordinates let the browser find the nearest branch itself.
                ->where('branches.0.latitude', 3.118)
                ->where('branches.0.longitude', 101.624)
                ->where('pricing', $this->currentRules())
                ->has('states', count(MalaysianState::cases())));
    }

    public function test_the_branches_page_lists_active_branches_by_state_and_city()
    {
        Branch::factory()->create(['name' => 'Shah Alam - Seksyen 13', 'city' => 'Shah Alam', 'state' => 'Selangor']);
        Branch::factory()->create(['name' => 'Bukit Bintang', 'city' => 'Kuala Lumpur', 'state' => 'Kuala Lumpur']);
        Branch::factory()->create(['name' => 'Petaling Jaya - SS2', 'city' => 'Petaling Jaya', 'state' => 'Selangor']);
        Branch::factory()->inactive()->create(['name' => 'Closed Branch']);

        $this->get(route('branches.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('branches/Index')
                ->has('branches', 3)
                ->where('branches.0.name', 'Bukit Bintang')
                ->where('branches.1.name', 'Petaling Jaya - SS2')
                ->where('branches.2.name', 'Shah Alam - Seksyen 13')
                ->has('branches.0', fn (Assert $branch) => $branch->hasAll([
                    'id', 'code', 'name', 'address', 'city', 'state', 'postcode',
                    'phone', 'latitude', 'longitude', 'opening_hours', 'is_active',
                ])));
    }

    public function test_the_pricing_page_shows_the_current_rates_by_zone()
    {
        $card = $this->zoneRates();
        $branch = Branch::factory()->create();

        $this->get(route('pricing'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pricing/Index')
                ->where('pricing', $this->currentRules())
                ->where('pricing.effectiveFrom', $card->effective_from?->toImmutable()->toIso8601ZuluString())
                // The card's id and name are for staff and admins.
                ->missing('pricing.id')
                ->missing('pricing.name')
                ->where('pricing.zones.1', ['code' => 'sabah-labuan', 'name' => 'Sabah & Labuan', 'states' => ['Sabah', 'Labuan']])
                ->where('pricing.routes.1.bands.0', ['maxWeightG' => 500, 'priceSen' => 900])
                ->where('pricing.maxWeightG', 30000)
                ->where('pricing.maxDimensionCm', 150)
                ->where('upcoming', null)
                // The estimator prices from a branch to a state.
                ->where('branches.0.id', $branch->id)
                ->has('states', count(MalaysianState::cases())));
    }

    public function test_the_pricing_page_announces_scheduled_rates()
    {
        $from = CarbonImmutable::now('Asia/Kuala_Lumpur')->addDays(10)->startOfDay();
        $this->zoneRates($from, 'Rates from next month');

        $this->get(route('pricing'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('pricing', $this->currentRules())
                // Only when; the name is for admins.
                ->where('upcoming', ['effectiveFrom' => $from->utc()->toIso8601ZuluString()]));
    }

    /**
     * The current rate card with the parcel limits, as visitors receive them.
     *
     * @return array<string, mixed>
     */
    private function currentRules(): array
    {
        return app(PriceCalculator::class)->publicRules(app(RateCards::class)->current());
    }
}
