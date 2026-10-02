<?php

namespace Tests\Feature\Staff;

use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CounterTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->branch = Branch::factory()->create();
        $this->staff = User::factory()->staff($this->branch)->create();
    }

    public function test_staff_see_the_parcels_most_recently_received_at_their_branch()
    {
        $older = Order::factory()->droppedOff()->for($this->branch)->create(['dropped_off_at' => now()->subHour()]);
        $newer = Order::factory()->paid()->for($this->branch)->create(['dropped_off_at' => now()]);
        Order::factory()->created()->for($this->branch)->create();
        Order::factory()->droppedOff()->create();

        $this->actingAs($this->staff)
            ->get(route('staff.counter'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/Counter')
                ->where('query', null)
                ->has('recent', 2)
                ->where('recent.0.id', $newer->id)
                ->where('recent.1.id', $older->id)
                ->where('recent.0.branch.id', $this->branch->id));
    }

    public function test_the_recent_list_is_limited_to_ten_parcels()
    {
        Order::factory()->count(12)->droppedOff()->for($this->branch)->create();

        $this->actingAs($this->staff)
            ->get(route('staff.counter'))
            ->assertInertia(fn (Assert $page) => $page->has('recent', 10));
    }

    public function test_admins_without_a_branch_see_parcels_received_at_every_branch()
    {
        Order::factory()->droppedOff()->for($this->branch)->create();
        Order::factory()->droppedOff()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('staff.counter'))
            ->assertInertia(fn (Assert $page) => $page->has('recent', 2));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function trackingNumberFormats(): array
    {
        return [
            'display form' => ['KT-7Q4M92XD'],
            'stored form' => ['KT7Q4M92XD'],
            'lower case' => ['kt-7q4m92xd'],
            'spaced' => ['  KT 7Q4M 92XD '],
        ];
    }

    #[DataProvider('trackingNumberFormats')]
    public function test_a_tracking_number_in_any_format_opens_the_parcel(string $number)
    {
        $order = Order::factory()->create(['tracking_number' => 'KT7Q4M92XD']);

        $this->actingAs($this->staff)
            ->get(route('staff.counter', ['number' => $number]))
            ->assertRedirect(route('staff.orders.show', $order));
    }

    public function test_an_unknown_tracking_number_shows_the_counter_again()
    {
        Order::factory()->create(['tracking_number' => 'KT7Q4M92XD']);

        $this->actingAs($this->staff)
            ->get(route('staff.counter', ['number' => 'KT-AAAAAAAA']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/Counter')
                ->where('query', 'KT-AAAAAAAA'));
    }

    public function test_a_malformed_search_is_rejected_cleanly()
    {
        $this->actingAs($this->staff)
            ->from(route('staff.counter'))
            ->get(route('staff.counter', ['number' => ['KT7Q4M92XD']]))
            ->assertRedirect(route('staff.counter'))
            ->assertSessionHasErrors('number');
    }

    public function test_the_parcel_page_has_what_the_counter_needs()
    {
        $order = Order::factory()->paid()->create();

        $this->actingAs($this->staff)
            ->get(route('staff.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/OrderShow')
                ->where('order.id', $order->id)
                ->where('order.status.value', 'paid')
                ->where('order.customer.id', $order->customer_id)
                ->where('order.payment.id', $order->payment?->id)
                ->has('order.payment.received_by')
                ->has('order.status_events', 3)
                ->has('order.status_events.0.actor')
                ->where('pricing.base', config('kotak.base_price_sen'))
                ->where('paymentMethods', [
                    ['value' => 'cash', 'label' => 'Cash'],
                    ['value' => 'card', 'label' => 'Card'],
                ]));
    }
}
