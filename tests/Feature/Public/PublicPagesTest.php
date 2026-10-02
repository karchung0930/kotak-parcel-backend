<?php

namespace Tests\Feature\Public;

use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The pricing rules from config/kotak.php, as the frontend receives them.
     *
     * @var array<string, int>
     */
    private const PRICING = [
        'base' => 800,
        'perKg' => 200,
        'divisor' => 5000,
        'maxWeightG' => 30000,
        'maxDimensionCm' => 150,
    ];

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
                ->where('pricing', self::PRICING));
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

    public function test_the_pricing_page_shows_the_configured_rules()
    {
        config(['kotak.base_price_sen' => 900]);

        $this->get(route('pricing'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pricing/Index')
                ->where('pricing', [...self::PRICING, 'base' => 900]));
    }
}
