<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Each role's home page belongs to its own module; stand in for any
        // that are not registered yet so the redirect itself can be tested.
        $homes = [
            'orders.index' => '/orders',
            'staff.counter' => '/staff/counter',
            'admin.dispatch' => '/admin/dispatch',
            'driver.jobs' => '/driver/jobs',
        ];

        foreach ($homes as $name => $uri) {
            if (! Route::has($name)) {
                Route::get($uri, fn () => 'ok')->name($name);
            }
        }

        Route::getRoutes()->refreshNameLookups();
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    /**
     * @return array<string, array{Role, string}>
     */
    public static function roles(): array
    {
        return [
            'customer' => [Role::Customer, 'orders.index'],
            'staff' => [Role::Staff, 'staff.counter'],
            'admin' => [Role::Admin, 'admin.dispatch'],
            'driver' => [Role::Driver, 'driver.jobs'],
        ];
    }

    #[DataProvider('roles')]
    public function test_users_are_sent_to_the_home_page_of_their_role(Role $role, string $home)
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route($home));
    }
}
