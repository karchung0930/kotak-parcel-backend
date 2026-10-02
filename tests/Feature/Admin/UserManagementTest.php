<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();

        $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
        $this->branch = Branch::factory()->create();
    }

    public function test_admins_list_accounts_by_role_and_search()
    {
        $driver = User::factory()->driver()->create(['name' => 'Ravi Kumar']);
        User::factory()->driver()->create(['name' => 'Wong Kah Wai']);
        User::factory()->staff($this->branch)->create(['name' => 'Ravi Staff']);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['role' => 'driver', 'q' => 'ravi']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/Index')
                ->has('users.data', 1)
                ->where('users.data.0.id', $driver->id)
                ->where('users.data.0.role.value', 'driver')
                ->where('filters', ['role' => 'driver', 'q' => 'ravi'])
                ->has('roles', 4));
    }

    public function test_a_filter_sent_as_a_list_is_rejected_cleanly()
    {
        foreach (['role', 'q'] as $filter) {
            $this->actingAs($this->admin)
                ->from(route('admin.users.index'))
                ->get(route('admin.users.index', [$filter => ['driver']]))
                ->assertRedirect(route('admin.users.index'))
                ->assertSessionHasErrors($filter);
        }
    }

    public function test_the_list_shows_each_staff_members_branch()
    {
        User::factory()->staff($this->branch)->create(['name' => 'Aina']);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['role' => 'staff']))
            ->assertInertia(fn (Assert $page) => $page->where('users.data.0.branch.id', $this->branch->id));
    }

    public function test_the_form_offers_only_active_branches()
    {
        Branch::factory()->inactive()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.users.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/Form')
                ->where('user', null)
                ->has('roles', 4)
                ->has('branches', 1)
                ->where('branches.0', [
                    'id' => $this->branch->id,
                    'code' => $this->branch->code,
                    'name' => $this->branch->name,
                    'city' => $this->branch->city,
                ]));
    }

    public function test_admins_create_a_staff_account_and_the_new_user_sets_their_own_password()
    {
        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), [
                'name' => 'Nurul Huda',
                'email' => 'nurul@kotak.test',
                'phone' => '012-345 6789',
                'role' => 'staff',
                'branch_id' => $this->branch->id,
                'vehicle_plate' => 'WXA 1234',
                'is_active' => true,
                'password' => 'chosen-by-admin',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'))
            ->assertInertiaFlash('toast.type', 'success');

        $user = User::query()->where('email', 'nurul@kotak.test')->sole();
        $this->assertSame(Role::Staff, $user->role);
        $this->assertSame($this->branch->id, $user->branch_id);
        $this->assertSame('+60123456789', $user->phone);
        $this->assertNull($user->vehicle_plate);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse(Hash::check('chosen-by-admin', $user->password));

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_admins_create_a_driver_with_a_vehicle_and_no_branch()
    {
        $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'Ravi Kumar',
            'email' => 'ravi@kotak.test',
            'phone' => '+60194000001',
            'role' => 'driver',
            'branch_id' => $this->branch->id,
            'vehicle_plate' => ' wxa   1234 ',
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $driver = User::query()->where('email', 'ravi@kotak.test')->sole();
        $this->assertSame(Role::Driver, $driver->role);
        $this->assertSame('WXA 1234', $driver->vehicle_plate);
        $this->assertNull($driver->branch_id);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidAccounts(): array
    {
        return [
            'staff without a branch' => [['role' => 'staff', 'branch_id' => null], 'branch_id'],
            'staff at a missing branch' => [['role' => 'staff', 'branch_id' => 999999], 'branch_id'],
            'driver without a vehicle' => [['role' => 'driver', 'vehicle_plate' => ''], 'vehicle_plate'],
            'vehicle plate with symbols' => [['role' => 'driver', 'vehicle_plate' => 'WXA-1234!'], 'vehicle_plate'],
            'unknown role' => [['role' => 'superuser'], 'role'],
            'landline phone' => [['phone' => '03-1234 5678'], 'phone'],
            'foreign phone' => [['phone' => '+6591234567'], 'phone'],
            'foreign phone with its country' => [['phone' => '+6591234567', 'phone_country' => 'SG'], 'phone'],
            'foreign phone with a country field' => [['phone' => '+6591234567', 'MY' => 'SG'], 'phone'],
            'missing phone' => [['phone' => ''], 'phone'],
            'invalid email' => [['email' => 'not-an-email'], 'email'],
            'missing name' => [['name' => ''], 'name'],
            'missing active flag' => [['is_active' => null], 'is_active'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidAccounts')]
    public function test_account_details_are_validated(array $overrides, string $field)
    {
        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), [...$this->validAccount(), ...$overrides])
            ->assertSessionHasErrors($field);

        $this->assertSame(1, User::count());
    }

    public function test_staff_cannot_be_assigned_to_an_inactive_branch()
    {
        $closed = Branch::factory()->inactive()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), [...$this->validAccount(), 'branch_id' => $closed->id])
            ->assertSessionHasErrors('branch_id');
    }

    public function test_email_addresses_are_unique()
    {
        User::factory()->create(['email' => 'taken@kotak.test']);

        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), [...$this->validAccount(), 'email' => 'taken@kotak.test'])
            ->assertSessionHasErrors('email');
    }

    public function test_the_edit_form_shows_the_account()
    {
        $staff = User::factory()->staff($this->branch)->create();

        $this->actingAs($this->admin)
            ->get(route('admin.users.edit', $staff))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/Form')
                ->where('user.id', $staff->id)
                ->where('user.branch.id', $this->branch->id)
                ->missing('user.password'));
    }

    public function test_admins_update_an_account_and_can_keep_its_email()
    {
        $staff = User::factory()->staff($this->branch)->create(['email' => 'aina@kotak.test']);
        $other = Branch::factory()->create();

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $staff), [
                ...$this->validAccount(),
                'name' => 'Aina Sofea',
                'email' => 'aina@kotak.test',
                'branch_id' => $other->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        $staff->refresh();
        $this->assertSame('Aina Sofea', $staff->name);
        $this->assertSame($other->id, $staff->branch_id);
    }

    public function test_changing_the_role_clears_details_of_the_old_role()
    {
        $driver = User::factory()->driver()->create();

        $this->actingAs($this->admin)->put(route('admin.users.update', $driver), [
            ...$this->validAccount(),
            'email' => $driver->email,
            'role' => 'staff',
            'branch_id' => $this->branch->id,
        ])->assertSessionHasNoErrors();

        $driver->refresh();
        $this->assertSame(Role::Staff, $driver->role);
        $this->assertSame($this->branch->id, $driver->branch_id);
        $this->assertNull($driver->vehicle_plate);
    }

    public function test_admins_deactivate_and_reactivate_accounts()
    {
        $staff = User::factory()->staff($this->branch)->create();
        $account = [...$this->validAccount(), 'email' => $staff->email];

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $staff), [...$account, 'is_active' => false])
            ->assertSessionHasNoErrors();
        $this->assertFalse($staff->refresh()->is_active);

        $this->actingAs($staff)->get(route('staff.counter'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $staff), [...$account, 'is_active' => true])
            ->assertSessionHasNoErrors();
        $this->assertTrue($staff->refresh()->is_active);
    }

    public function test_admins_cannot_deactivate_or_demote_themselves()
    {
        $account = [
            ...$this->validAccount(),
            'email' => $this->admin->email,
            'role' => 'admin',
            'branch_id' => null,
        ];

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $this->admin), [...$account, 'is_active' => false])
            ->assertSessionHasErrors('is_active');

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $this->admin), [...$account, 'role' => 'staff', 'branch_id' => $this->branch->id])
            ->assertSessionHasErrors('role');

        $this->admin->refresh();
        $this->assertSame(Role::Admin, $this->admin->role);
        $this->assertTrue($this->admin->is_active);
    }

    public function test_admins_can_demote_another_admin()
    {
        $other = User::factory()->admin()->create();

        $this->actingAs($this->admin)->put(route('admin.users.update', $other), [
            ...$this->validAccount(),
            'email' => $other->email,
            'role' => 'customer',
        ])->assertSessionHasNoErrors();

        $this->assertSame(Role::Customer, $other->refresh()->role);
        $this->assertNull($other->branch_id);
    }

    public function test_a_driver_with_deliveries_in_progress_keeps_their_account()
    {
        $driver = User::factory()->driver()->create();
        Order::factory()->pickedUp($driver)->create();
        $account = [...$this->validAccount(), 'email' => $driver->email, 'role' => 'driver', 'vehicle_plate' => 'WXA 1234'];

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $driver), [...$account, 'is_active' => false])
            ->assertSessionHasErrors(['is_active' => 'This driver still has 1 delivery in progress.']);

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $driver), [...$account, 'role' => 'customer'])
            ->assertSessionHasErrors('is_active');

        $driver->refresh();
        $this->assertTrue($driver->is_active);
        $this->assertSame(Role::Driver, $driver->role);
    }

    public function test_a_driver_whose_deliveries_are_finished_can_be_deactivated()
    {
        $driver = User::factory()->driver()->create();
        Order::factory()->delivered($driver)->create();

        $this->actingAs($this->admin)->put(route('admin.users.update', $driver), [
            ...$this->validAccount(),
            'email' => $driver->email,
            'role' => 'driver',
            'vehicle_plate' => 'WXA 1234',
            'is_active' => false,
        ])->assertSessionHasNoErrors();

        $this->assertFalse($driver->refresh()->is_active);
    }

    /**
     * A valid staff account.
     *
     * @return array<string, mixed>
     */
    private function validAccount(): array
    {
        return [
            'name' => 'Lim Mei Ling',
            'email' => 'meiling@kotak.test',
            'phone' => '+60123000001',
            'role' => 'staff',
            'branch_id' => $this->branch->id,
            'vehicle_plate' => null,
            'is_active' => true,
        ];
    }
}
