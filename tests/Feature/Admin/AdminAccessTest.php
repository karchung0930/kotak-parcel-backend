<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function outsiders(): array
    {
        return [
            'customer' => [Role::Customer],
            'driver' => [Role::Driver],
            'branch staff' => [Role::Staff],
        ];
    }

    #[DataProvider('outsiders')]
    public function test_only_admins_can_use_any_admin_route(Role $role)
    {
        $user = User::factory()->create(['role' => $role]);
        $driver = User::factory()->driver()->create();
        $paid = Order::factory()->paid()->create();
        $failed = Order::factory()->deliveryFailed()->create();

        $this->actingAs($user);

        foreach ($this->routes($user, $paid, $failed) as [$method, $uri]) {
            $this->{$method}($uri, [
                'driver_id' => $driver->id,
                'scheduled_for' => today(config('kotak.timezone'))->toDateString(),
                'role' => 'admin',
                'is_active' => true,
            ])->assertForbidden();
        }

        $this->assertSame(OrderStatus::Paid, $paid->refresh()->status);
        $this->assertSame(OrderStatus::DeliveryFailed, $failed->refresh()->status);
        $this->assertSame($role, $user->refresh()->role);
    }

    public function test_guests_are_sent_to_log_in()
    {
        $user = User::factory()->create();

        foreach ($this->routes($user, Order::factory()->paid()->create(), Order::factory()->deliveryFailed()->create()) as [$method, $uri]) {
            $this->{$method}($uri)->assertRedirect(route('login'));
        }
    }

    public function test_admins_can_open_every_admin_page()
    {
        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create();
        $branch = Branch::factory()->create();

        $this->actingAs($admin);

        $this->get(route('admin.dispatch'))->assertOk();
        $this->get(route('admin.orders.index'))->assertOk();
        $this->get(route('admin.orders.show', $order))->assertOk();
        $this->get(route('admin.users.index'))->assertOk();
        $this->get(route('admin.users.create'))->assertOk();
        $this->get(route('admin.users.edit', $admin))->assertOk();
        $this->get(route('admin.branches.index'))->assertOk();
        $this->get(route('admin.branches.create'))->assertOk();
        $this->get(route('admin.branches.edit', $branch))->assertOk();
        $this->get(route('admin.settings.edit'))->assertOk();
    }

    /**
     * Every admin route, as [method, uri].
     *
     * @return list<array{string, string}>
     */
    private function routes(User $user, Order $paid, Order $failed): array
    {
        $branch = Branch::factory()->create();

        return [
            ['get', route('admin.dispatch')],
            ['post', route('admin.orders.assign', $paid)],
            ['post', route('admin.orders.return', $failed)],
            ['get', route('admin.orders.index')],
            ['get', route('admin.orders.show', $paid)],
            ['get', route('admin.users.index')],
            ['get', route('admin.users.create')],
            ['post', route('admin.users.store')],
            ['get', route('admin.users.edit', $user)],
            ['put', route('admin.users.update', $user)],
            ['get', route('admin.branches.index')],
            ['get', route('admin.branches.create')],
            ['post', route('admin.branches.store')],
            ['get', route('admin.branches.edit', $branch)],
            ['put', route('admin.branches.update', $branch)],
            ['get', route('admin.settings.edit')],
            ['put', route('admin.settings.update')],
        ];
    }
}
