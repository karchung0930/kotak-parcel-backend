<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register()
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '012-345 6789',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'test@example.com')->firstOrFail();
        $this->assertSame('+60123456789', $user->phone);
        $this->assertSame(Role::Customer, $user->role);
        $this->assertTrue($user->is_active);
    }

    public function test_the_e164_number_the_form_sends_is_stored_as_it_is()
    {
        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '+601123456789',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors();

        $this->assertSame('+601123456789', User::where('email', 'test@example.com')->firstOrFail()->phone);
    }

    public function test_a_malaysian_mobile_number_is_required()
    {
        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('phone');

        // A landline, a foreign number (also with a country sent along), an unallocated range, text.
        $cases = [
            ['phone' => '03-7877 1203'],
            ['phone' => '+6591234567'],
            ['phone' => '+6591234567', 'phone_country' => 'SG'],
            ['phone' => '+6591234567', 'MY' => 'SG'],
            ['phone' => '010-123 4567'],
            ['phone' => 'not a phone'],
        ];

        foreach ($cases as $case) {
            $this->post(route('register.store'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
                ...$case,
            ])->assertSessionHasErrors(['phone' => 'Enter a valid Malaysian mobile number, e.g. 12-345 6789.']);
        }

        $this->assertGuest();
    }

    public function test_registration_cannot_choose_a_role()
    {
        $this->post(route('register.store'), [
            'name' => 'Sneaky User',
            'email' => 'sneaky@example.com',
            'phone' => '0123456789',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'admin',
            'is_active' => false,
            'branch_id' => 1,
        ]);

        $user = User::where('email', 'sneaky@example.com')->firstOrFail();
        $this->assertSame(Role::Customer, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->branch_id);
    }
}
