<?php

namespace Tests\Feature\Http;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BrowserHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_pages_with_personal_data_are_encrypted_in_the_browser_history()
    {
        $order = Order::factory()->create();

        $this->actingAs($order->customer)
            ->get(route('orders.show', $order))
            ->assertInertia(fn (Assert $page) => $this->assertTrue($page->toArray()['encryptHistory'] ?? false));
    }

    public function test_signing_out_clears_the_browser_history()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('logout'))
            ->assertRedirect(route('home'));

        $this->get(route('home'))
            ->assertInertia(fn (Assert $page) => $this->assertTrue($page->toArray()['clearHistory'] ?? false));

        // Only once: later pages start a fresh history.
        $this->get(route('home'))
            ->assertInertia(fn (Assert $page) => $this->assertArrayNotHasKey('clearHistory', $page->toArray()));
    }

    public function test_deleting_the_account_clears_the_browser_history()
    {
        $this->actingAs(User::factory()->create())
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect(route('home'));

        $this->get(route('home'))
            ->assertInertia(fn (Assert $page) => $this->assertTrue($page->toArray()['clearHistory'] ?? false));
    }
}
