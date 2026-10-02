<?php

namespace Tests\Feature\Http;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'role:staff,admin'])->get('/_test/counter', fn () => 'counter');
        Route::middleware(['web', 'auth', 'role:driver'])->get('/_test/jobs', fn () => 'jobs');
    }

    public function test_users_with_a_listed_role_are_let_through()
    {
        $this->actingAs(User::factory()->staff()->create())->get('/_test/counter')->assertOk();
        $this->actingAs(User::factory()->admin()->create())->get('/_test/counter')->assertOk();
        $this->actingAs(User::factory()->driver()->create())->get('/_test/jobs')->assertOk();
    }

    public function test_other_roles_are_forbidden()
    {
        $this->actingAs(User::factory()->create())->get('/_test/counter')->assertForbidden();
        $this->actingAs(User::factory()->driver()->create())->get('/_test/counter')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/_test/jobs')->assertForbidden();
    }

    public function test_guests_are_sent_to_log_in()
    {
        $this->get('/_test/counter')->assertRedirect(route('login'));
    }

    public function test_the_module_route_groups_are_protected_by_role()
    {
        $groups = [
            'routes/customer.php' => "'role:customer'",
            'routes/staff.php' => "'role:staff,admin'",
            'routes/admin.php' => "'role:admin'",
            'routes/driver.php' => "'role:driver'",
        ];

        foreach ($groups as $file => $middleware) {
            $this->assertStringContainsString($middleware, (string) file_get_contents(base_path($file)), $file);
        }
    }
}
