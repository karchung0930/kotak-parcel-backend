<?php

namespace Tests\Feature\Staff;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaffAccessTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->order = Order::factory()->droppedOff()->create();
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function outsiders(): array
    {
        return [
            'customer' => [Role::Customer],
            'driver' => [Role::Driver],
        ];
    }

    #[DataProvider('outsiders')]
    public function test_customers_and_drivers_cannot_use_any_counter_route(Role $role)
    {
        $this->actingAs(User::factory()->create(['role' => $role]));

        foreach ($this->routes() as [$method, $uri]) {
            $this->{$method}($uri, [
                'measured_weight_g' => 1000,
                'method' => 'cash',
                'amount_sen' => $this->order->final_price_sen,
            ])->assertForbidden();
        }

        $this->order->refresh();
        $this->assertSame(OrderStatus::DroppedOff, $this->order->status);
        $this->assertFalse($this->order->payment()->exists());
    }

    public function test_customers_cannot_process_even_their_own_parcel()
    {
        $this->actingAs($this->order->customer);

        $this->post(route('staff.orders.payment', $this->order), [
            'method' => 'cash',
            'amount_sen' => $this->order->final_price_sen,
        ])->assertForbidden();

        $this->get(route('staff.orders.show', $this->order))->assertForbidden();
    }

    public function test_guests_are_sent_to_log_in()
    {
        foreach ($this->routes() as [$method, $uri]) {
            $this->{$method}($uri)->assertRedirect(route('login'));
        }
    }

    public function test_staff_and_admins_can_open_the_counter()
    {
        $this->actingAs(User::factory()->staff()->create())
            ->get(route('staff.counter'))
            ->assertOk();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('staff.counter'))
            ->assertOk();
    }

    /**
     * Every counter route, as [method, uri].
     *
     * @return list<array{string, string}>
     */
    private function routes(): array
    {
        $payment = Payment::factory()->create();

        return [
            ['get', route('staff.counter')],
            ['get', route('staff.orders.show', $this->order)],
            ['post', route('staff.orders.drop-off', $this->order)],
            ['post', route('staff.orders.payment', $this->order)],
            ['post', route('staff.orders.cancel', $this->order)],
            ['get', route('staff.payments.receipt', $payment)],
        ];
    }
}
