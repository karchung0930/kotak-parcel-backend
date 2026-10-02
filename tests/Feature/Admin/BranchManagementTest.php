<?php

namespace Tests\Feature\Admin;

use App\Enums\MalaysianState;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BranchManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_admins_see_every_branch_including_deactivated_ones()
    {
        Branch::factory()->create(['name' => 'Bangsar']);
        Branch::factory()->inactive()->create(['name' => 'Ampang']);

        $this->actingAs($this->admin)
            ->get(route('admin.branches.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/branches/Index')
                ->has('branches', 2)
                ->where('branches.0.name', 'Ampang')
                ->where('branches.0.is_active', false));
    }

    public function test_the_form_offers_every_malaysian_state()
    {
        $this->actingAs($this->admin)
            ->get(route('admin.branches.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/branches/Form')
                ->where('branch', null)
                ->has('states', count(MalaysianState::cases())));
    }

    public function test_admins_open_a_branch()
    {
        $this->actingAs($this->admin)
            ->post(route('admin.branches.store'), [...$this->validBranch(), 'code' => ' pj-ss2 '])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.branches.index'))
            ->assertInertiaFlash('toast.type', 'success');

        $branch = Branch::query()->sole();
        $this->assertSame('PJ-SS2', $branch->code);
        $this->assertSame(MalaysianState::Selangor, $branch->state);
        $this->assertSame('3.1177000', $branch->latitude);
        $this->assertTrue($branch->is_active);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidBranches(): array
    {
        return [
            'code with symbols' => [['code' => 'PJ SS2!'], 'code'],
            'four digit postcode' => [['postcode' => '4630'], 'postcode'],
            'unknown state' => [['state' => 'Bali'], 'state'],
            'latitude outside Malaysia' => [['latitude' => 51.5], 'latitude'],
            'longitude outside Malaysia' => [['longitude' => -0.12], 'longitude'],
            'missing opening hours' => [['opening_hours' => ''], 'opening_hours'],
            'invalid phone' => [['phone' => 'call us'], 'phone'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidBranches')]
    public function test_branch_details_are_validated(array $overrides, string $field)
    {
        $this->actingAs($this->admin)
            ->post(route('admin.branches.store'), [...$this->validBranch(), ...$overrides])
            ->assertSessionHasErrors($field);

        $this->assertSame(0, Branch::count());
    }

    public function test_branch_codes_are_unique()
    {
        Branch::factory()->create(['code' => 'PJ-SS2']);

        $this->actingAs($this->admin)
            ->post(route('admin.branches.store'), [...$this->validBranch(), 'code' => 'pj-ss2'])
            ->assertSessionHasErrors('code');
    }

    public function test_admins_update_a_branch_and_keep_its_code()
    {
        $branch = Branch::factory()->create(['code' => 'PJ-SS2']);

        $this->actingAs($this->admin)
            ->get(route('admin.branches.edit', $branch))
            ->assertInertia(fn (Assert $page) => $page->where('branch.id', $branch->id));

        $this->actingAs($this->admin)
            ->put(route('admin.branches.update', $branch), [...$this->validBranch(), 'name' => 'SS2 Flagship'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.branches.index'));

        $this->assertSame('SS2 Flagship', $branch->refresh()->name);
    }

    public function test_a_deactivated_branch_keeps_its_parcels_and_history()
    {
        $branch = Branch::factory()->create(['code' => 'PJ-SS2']);
        $order = Order::factory()->paid()->for($branch)->create();

        $this->actingAs($this->admin)
            ->put(route('admin.branches.update', $branch), [...$this->validBranch(), 'is_active' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($branch->refresh()->is_active);
        $this->assertSame($branch->id, $order->refresh()->branch_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function validBranch(): array
    {
        return [
            'code' => 'PJ-SS2',
            'name' => 'Petaling Jaya SS2',
            'address' => '12, Jalan SS 2/24',
            'city' => 'Petaling Jaya',
            'state' => 'Selangor',
            'postcode' => '47300',
            'phone' => '03-7875 1234',
            'latitude' => 3.1177,
            'longitude' => 101.6227,
            'opening_hours' => 'Mon-Sat 9:00-21:00, Sun 10:00-18:00',
            'is_active' => true,
        ];
    }
}
