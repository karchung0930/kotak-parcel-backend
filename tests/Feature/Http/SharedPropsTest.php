<?php

namespace Tests\Feature\Http;

use App\Actions\Settings\UpdateSettings;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SharedPropsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_a_small_view_of_the_signed_in_user_is_shared()
    {
        $branch = Branch::factory()->create(['name' => 'Petaling Jaya - SS2']);
        $user = User::factory()->staff($branch)->withTwoFactor()->create([
            'name' => 'Nurul Huda',
            'phone' => '+60123000001',
        ]);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user', [
                    'id' => $user->id,
                    'name' => 'Nurul Huda',
                    'email' => $user->email,
                    'phone' => '+60123000001',
                    'email_verified_at' => $user->email_verified_at?->toIso8601ZuluString(),
                    'role' => ['value' => 'staff', 'label' => 'Branch Staff'],
                    'branch_name' => 'Petaling Jaya - SS2',
                ]));
    }

    public function test_guests_share_no_user()
    {
        $this->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.user', null));
    }

    public function test_the_delivery_attempt_limit_is_shared_from_config()
    {
        config(['kotak.max_failed_attempts' => 4]);

        $this->get(route('pricing'))
            ->assertInertia(fn (Assert $page) => $page->where('maxFailedAttempts', 4));
    }

    public function test_the_delivery_attempt_limit_an_admin_saved_is_shared()
    {
        app(UpdateSettings::class)->handle(User::factory()->admin()->create(), ['max_failed_attempts' => 5]);

        $this->get(route('pricing'))
            ->assertInertia(fn (Assert $page) => $page->where('maxFailedAttempts', 5));
    }
}
