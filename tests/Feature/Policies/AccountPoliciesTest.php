<?php

namespace Tests\Feature\Policies;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AccountPoliciesTest extends TestCase
{
    use RefreshDatabase;

    public function test_payments_are_visible_to_staff_and_the_paying_customer()
    {
        $order = Order::factory()->paid()->create();
        $payment = $order->payment()->firstOrFail();

        $this->assertTrue(User::factory()->staff()->create()->can('view', $payment));
        $this->assertTrue(User::factory()->admin()->create()->can('view', $payment));
        $this->assertTrue($order->customer->can('view', $payment));
        $this->assertFalse(User::factory()->create()->can('view', $payment));
        $this->assertFalse(User::factory()->driver()->create()->can('view', $payment));

        $this->assertTrue(User::factory()->staff()->create()->can('create', Payment::class));
        $this->assertFalse($order->customer->can('create', Payment::class));
    }

    public function test_branches_are_public_but_only_admins_manage_them()
    {
        $branch = Branch::factory()->create();

        $this->assertTrue(Gate::forUser(null)->allows('viewAny', Branch::class));
        $this->assertTrue(Gate::forUser(null)->allows('view', $branch));
        $this->assertTrue(User::factory()->admin()->create()->can('create', Branch::class));
        $this->assertTrue(User::factory()->admin()->create()->can('update', $branch));
        $this->assertFalse(User::factory()->staff()->create()->can('update', $branch));
        $this->assertFalse(User::factory()->create()->can('create', Branch::class));
    }

    public function test_only_admins_manage_user_accounts()
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();

        $this->assertTrue($admin->can('viewAny', User::class));
        $this->assertTrue($admin->can('create', User::class));
        $this->assertTrue($admin->can('update', $staff));
        $this->assertFalse($staff->can('viewAny', User::class));
        $this->assertFalse($staff->can('update', $staff));
        $this->assertTrue($staff->can('view', $staff));
        $this->assertFalse($staff->can('view', $admin));
    }
}
