<?php

namespace Tests\Feature\Policies;

use App\Enums\Role;
use App\Models\RateImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RateImportPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_import_rates_and_pick_up_each_other_s_imports()
    {
        $admin = User::factory()->admin()->create();
        $import = RateImport::factory()->create();

        $this->assertNotSame($admin->id, $import->user_id);
        $this->assertTrue($admin->can('viewAny', RateImport::class));
        $this->assertTrue($admin->can('create', RateImport::class));
        $this->assertTrue($admin->can('view', $import));
        $this->assertTrue($admin->can('update', $import));
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
    public function test_nobody_else_can(Role $role)
    {
        $user = User::factory()->create(['role' => $role]);
        $import = RateImport::factory()->create(['user_id' => $user->id]);

        $this->assertFalse($user->can('viewAny', RateImport::class));
        $this->assertFalse($user->can('create', RateImport::class));
        $this->assertFalse($user->can('view', $import));
        $this->assertFalse($user->can('update', $import));
    }
}
