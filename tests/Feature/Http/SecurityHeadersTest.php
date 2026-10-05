<?php

namespace Tests\Feature\Http;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_responses_carry_browser_hardening_headers()
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(self)')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_the_camera_is_allowed_on_the_scanning_pages_for_this_site_only()
    {
        $staff = User::factory()->staff()->create();
        $driver = User::factory()->driver()->create();

        foreach ([[$staff, route('staff.counter')], [$driver, route('driver.jobs')]] as [$user, $url]) {
            $policy = (string) $this->actingAs($user)->get($url)->assertOk()->headers->get('Permissions-Policy');

            // Scanning needs camera=(self): no other origin, and no microphone.
            $this->assertStringContainsString('camera=(self)', $policy);
            $this->assertStringContainsString('microphone=()', $policy);
            $this->assertStringNotContainsString('camera=*', $policy);
        }
    }

    public function test_hsts_is_sent_over_https_only()
    {
        $this->get('https://localhost/')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_error_responses_are_covered_too()
    {
        $this->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertHeader('X-Frame-Options', 'DENY');
    }
}
