<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CredentialCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_login_takes_the_same_minimum_time_whether_or_not_the_email_exists()
    {
        $user = User::factory()->create();

        foreach (['nobody@example.com', $user->email] as $email) {
            $started = microtime(true);

            $this->post(route('login.store'), ['email' => $email, 'password' => 'wrong-password'])
                ->assertSessionHasErrors(['email' => __('auth.failed')]);

            // Fortify's default 200 ms window, so response times do not reveal accounts.
            $this->assertGreaterThanOrEqual(0.19, microtime(true) - $started, $email);
        }

        $this->assertGuest();
    }

    public function test_outdated_password_hashes_are_upgraded_when_signing_in()
    {
        // The test suite hashes with 4 bcrypt rounds (phpunit.xml).
        $user = User::factory()->create();
        $this->assertStringStartsWith('$2y$04$', $user->password);

        config(['hashing.bcrypt.rounds' => 5]);
        Hash::forgetDrivers();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
        $this->assertStringStartsWith('$2y$05$', (string) $user->fresh()?->password);
    }
}
