<?php

namespace Tests\Feature\Http;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InactiveUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivated_users_cannot_log_in()
    {
        $user = User::factory()->staff()->inactive()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors(['email' => 'Your account has been deactivated. Please contact your administrator.']);

        $this->assertGuest();
    }

    public function test_the_deactivation_message_is_only_shown_after_a_correct_password()
    {
        $user = User::factory()->inactive()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors(['email' => __('auth.failed')]);

        $this->assertGuest();
    }

    public function test_users_deactivated_while_logged_in_are_signed_out()
    {
        $user = User::factory()->staff()->create();
        $this->actingAs($user);

        $user->forceFill(['is_active' => false])->save();

        $this->get(route('profile.edit'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();

        // Their pages are wiped from the browser history too.
        $this->get(route('login'))
            ->assertInertia(fn (Assert $page) => $this->assertTrue($page->toArray()['clearHistory'] ?? false));
    }

    public function test_active_users_are_unaffected()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('profile.edit'))
            ->assertOk();
    }
}
