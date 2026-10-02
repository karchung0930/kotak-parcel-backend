<?php

namespace Tests\Feature\Customer;

use App\Enums\Role;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pages are checked through their Inertia props, not the built assets.
        $this->withoutVite();
    }

    public function test_customers_see_only_their_own_orders_newest_first_ten_per_page()
    {
        $customer = User::factory()->create();
        $orders = Order::factory()
            ->count(12)
            ->for($customer, 'customer')
            ->state(new Sequence(fn (Sequence $sequence) => ['created_at' => now()->subDays($sequence->index)]))
            ->create();
        // Another customer's order is not counted.
        Order::factory()->create();

        $this->actingAs($customer)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('orders/Index')
                ->has('orders.data', 10)
                ->where('orders.meta.total', 12)
                ->where('orders.meta.per_page', 10)
                ->where('orders.data.0.id', $orders[0]->id)
                ->where('orders.data.9.id', $orders[9]->id)
                ->where('orders.data.0.tracking_number', $orders[0]->formatted_tracking_number)
                ->has('orders.data.0.branch')
                ->missing('orders.data.0.customer')
                ->has('orders.links.next'));

        $this->actingAs($customer)
            ->get(route('orders.index', ['page' => 2]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 2)
                ->where('orders.data.1.id', $orders[11]->id));
    }

    public function test_guests_are_sent_to_the_login_page()
    {
        $this->get(route('orders.index'))->assertRedirect(route('login'));
    }

    public function test_customers_must_verify_their_email_first()
    {
        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('orders.index'))
            ->assertRedirect(route('verification.notice'));
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function otherRoles(): array
    {
        return [
            'staff' => [Role::Staff],
            'admin' => [Role::Admin],
            'driver' => [Role::Driver],
        ];
    }

    #[DataProvider('otherRoles')]
    public function test_other_roles_cannot_use_the_customer_pages(Role $role)
    {
        $user = User::factory()->create(['role' => $role]);
        $order = Order::factory()->create();

        $this->actingAs($user)->get(route('orders.index'))->assertForbidden();
        $this->actingAs($user)->get(route('orders.create'))->assertForbidden();
        $this->actingAs($user)->get(route('orders.show', $order))->assertForbidden();
        $this->actingAs($user)->post(route('orders.cancel', $order))->assertForbidden();
    }
}
