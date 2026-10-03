<?php

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->admin = User::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        // A static setting: do not let it leak into other tests.
        Model::preventLazyLoading(false);

        parent::tearDown();
    }

    public function test_admins_see_every_order_newest_first_twenty_per_page()
    {
        $orders = Order::factory()->count(22)->create();

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/orders/Index')
                ->has('orders.data', 20)
                ->where('orders.data.0.id', $orders->last()?->id)
                ->has('orders.data.0.branch')
                ->has('orders.data.0.customer')
                ->where('orders.meta.total', 22)
                ->where('orders.meta.last_page', 2)
                ->where('filters', ['status' => null, 'q' => null, 'branch' => null])
                ->has('statuses', 9)
                ->has('branches', 22));
    }

    public function test_orders_are_filtered_by_status_and_branch()
    {
        $branch = Branch::factory()->create();
        $match = Order::factory()->paid()->for($branch)->create();
        Order::factory()->paid()->create();
        Order::factory()->created()->for($branch)->create();

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index', ['status' => 'paid', 'branch' => $branch->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $match->id)
                ->where('filters.status', 'paid')
                ->where('filters.branch', $branch->id));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function searches(): array
    {
        return [
            'tracking number' => ['KT-7Q4M92XD'],
            'tracking number typed loosely' => ['kt 7q4m92xd'],
            'receiver name' => ['siti nur'],
            'sender name' => ['Tan Ah'],
        ];
    }

    #[DataProvider('searches')]
    public function test_orders_are_found_by_tracking_number_or_name(string $search)
    {
        $customer = User::factory()->create(['name' => 'Tan Ah Kow']);
        $match = Order::factory()->for($customer, 'customer')->create([
            'tracking_number' => 'KT7Q4M92XD',
            'receiver_name' => 'Siti Nurhaliza',
        ]);
        Order::factory()->create(['receiver_name' => 'Someone Else']);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index', ['q' => $search]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $match->id)
                ->where('filters.q', trim($search)));
    }

    public function test_unknown_filter_values_are_ignored()
    {
        Order::factory()->count(2)->create();

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index', ['status' => 'lost', 'branch' => 'abc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 2)
                ->where('filters', ['status' => null, 'q' => null, 'branch' => null]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function filters(): array
    {
        return ['status' => ['status'], 'branch' => ['branch'], 'search' => ['q']];
    }

    #[DataProvider('filters')]
    public function test_a_filter_sent_as_a_list_is_rejected_cleanly(string $filter)
    {
        $this->actingAs($this->admin)
            ->from(route('admin.orders.index'))
            ->get(route('admin.orders.index', [$filter => ['paid']]))
            ->assertRedirect(route('admin.orders.index'))
            ->assertSessionHasErrors($filter);
    }

    public function test_pagination_links_keep_the_filters()
    {
        Order::factory()->count(21)->paid()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index', ['status' => 'paid']))
            ->assertInertia(fn (Assert $page) => $page->where(
                'orders.links.next',
                fn (string $next) => str_contains($next, 'status=paid') && str_contains($next, 'page=2'),
            ));
    }

    public function test_the_list_eager_loads_its_relations()
    {
        Model::preventLazyLoading();

        Order::factory()->assigned()->create();

        // The settings are read once and then cached, so warm them up first.
        app(Settings::class)->all();

        $few = $this->countQueries(fn () => $this->actingAs($this->admin)->get(route('admin.orders.index'))->assertOk());

        Order::factory()->count(6)->assigned()->create();

        $many = $this->countQueries(fn () => $this->actingAs($this->admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 7)
                ->has('orders.data.6.branch')
                ->has('orders.data.6.customer')
                ->has('orders.data.6.driver.vehicle_plate')));

        $this->assertSame($few, $many);
    }

    public function test_the_order_page_shows_everything_about_the_order()
    {
        $order = Order::factory()->deliveryFailed()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/orders/Show')
                ->where('order.id', $order->id)
                ->where('order.status.value', 'delivery_failed')
                ->where('order.customer.id', $order->customer_id)
                ->where('order.driver.id', $order->driver_id)
                ->has('order.branch')
                ->has('order.payment.received_by')
                ->has('order.delivery_attempts', 1)
                ->has('order.delivery_attempts.0.driver')
                ->has('order.status_events', 6)
                ->where('order.failed_attempts', 1)
                // Which rate card priced the estimate and the final price.
                ->where('order.estimated_rate_card', ['id' => $order->estimated_rate_card_id, 'name' => 'Standard rates'])
                ->where('order.final_rate_card', ['id' => $order->final_rate_card_id, 'name' => 'Standard rates'])
                ->where('maxFailedAttempts', 3));
    }

    public function test_an_order_that_can_be_assigned_comes_with_the_drivers_and_their_workload()
    {
        $today = today(config('kotak.timezone'));
        $busy = User::factory()->driver()->create(['name' => 'Ahmad']);
        User::factory()->driver()->create(['name' => 'Bala']);
        User::factory()->driver()->inactive()->create();
        $order = Order::factory()->assigned($busy)->create(['scheduled_for' => $today->toDateString()]);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('date', $today->toDateString())
                ->where('today', $today->toDateString())
                ->has('drivers', 2)
                ->where('drivers.0.id', $busy->id)
                ->where('drivers.0.jobs_count', 1)
                ->where('drivers.1.jobs_count', 0));

        $this->actingAs($this->admin)
            ->get(route('admin.orders.show', [$order, 'date' => $today->addDay()->toDateString()]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('date', $today->addDay()->toDateString())
                ->where('drivers.0.jobs_count', 0));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unassignableOrders(): array
    {
        return ['out for delivery' => ['pickedUp'], 'delivered' => ['delivered'], 'cancelled' => ['cancelled']];
    }

    #[DataProvider('unassignableOrders')]
    public function test_an_order_that_cannot_be_assigned_comes_without_drivers(string $state)
    {
        User::factory()->driver()->create();
        $order = Order::factory()->{$state}()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page->where('drivers', []));
    }

    public function test_an_invalid_workload_day_is_rejected()
    {
        $order = Order::factory()->paid()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.orders.show', [$order, 'date' => '2026-02-30']))
            ->assertSessionHasErrors('date');
    }

    /**
     * Count the database queries run by the callback.
     */
    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
