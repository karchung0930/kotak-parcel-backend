<?php

namespace Tests\Feature\Settings;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'phone' => '016-234 5678',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertSame('+60162345678', $user->phone);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
                'phone' => $user->phone,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->fresh());
    }

    public function test_a_valid_mobile_number_is_required()
    {
        $user = User::factory()->create();

        $cases = [
            ['phone' => '03-7877 1203'],
            ['phone' => '+6591234567', 'phone_country' => 'SG'],
            ['phone' => '+6591234567', 'MY' => 'SG'],
            ['phone' => '12345'],
        ];

        foreach ($cases as $case) {
            $this
                ->actingAs($user)
                ->patch(route('profile.update'), [
                    'name' => 'Test User',
                    'email' => $user->email,
                    ...$case,
                ])
                ->assertSessionHasErrors(['phone' => 'Enter a valid Malaysian mobile number, e.g. 12-345 6789.']);
        }

        $this->assertNotSame('+6591234567', $user->refresh()->phone);
    }

    public function test_the_e164_number_the_form_sends_is_stored()
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => '+60193456789',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('+60193456789', $user->refresh()->phone);
    }

    public function test_the_role_cannot_be_changed_from_the_profile()
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => 'admin',
                'is_active' => false,
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertTrue($user->isCustomer());
        $this->assertTrue($user->is_active);
    }

    public function test_accounts_with_parcels_cannot_be_deleted()
    {
        $order = Order::factory()->create();

        $this
            ->actingAs($order->customer)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors('password');

        $this->assertNotNull($order->customer->fresh());
    }

    public function test_staff_accounts_cannot_be_deleted_by_their_owner()
    {
        $staff = User::factory()->staff()->create();

        $this
            ->actingAs($staff)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors('password');

        $this->assertNotNull($staff->fresh());
    }
}
