<?php

namespace Tests\Feature\Broadcasting;

use App\Broadcasting\OrderChannel;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

class OrderChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_customer_who_placed_the_order_may_listen_to_it()
    {
        $order = Order::factory()->pickedUp()->create();
        $channel = new OrderChannel;

        $this->assertTrue($channel->join($order->customer, $order));

        foreach ([
            User::factory()->customer()->create(),
            User::factory()->staff()->create(),
            User::factory()->admin()->create(),
            $order->driver,
        ] as $user) {
            $this->assertNotNull($user);
            $this->assertFalse($channel->join($user, $order), $user->role->value);
        }

        $order->customer->forceFill(['email_verified_at' => null])->save();
        $this->assertFalse($channel->join($order->customer, $order));
    }

    public function test_reverb_signs_the_subscription_for_the_owner_only()
    {
        $this->signWithReverb();
        $order = Order::factory()->pickedUp()->create();
        $request = ['socket_id' => '1234.5678', 'channel_name' => "private-orders.{$order->id}"];

        $this->post('/broadcasting/auth', $request)->assertForbidden();

        $this->actingAs($order->customer)
            ->post('/broadcasting/auth', $request)
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->actingAs(User::factory()->customer()->create())
            ->post('/broadcasting/auth', $request)
            ->assertForbidden();
    }

    /**
     * Sign subscriptions as Reverb does. The tests broadcast to nothing
     * (BROADCAST_CONNECTION=null), so the channels are registered again on
     * a Reverb broadcaster with test credentials.
     */
    private function signWithReverb(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');
    }
}
