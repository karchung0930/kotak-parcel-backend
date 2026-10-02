<?php

namespace Tests\Feature\Http;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_tracking_is_limited_to_30_lookups_a_minute_per_visitor()
    {
        $request = Request::create('/track', server: ['REMOTE_ADDR' => '203.0.113.7']);

        $limit = $this->resolve('tracking', $request);

        $this->assertSame(30, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('203.0.113.7', $limit->key);
    }

    public function test_order_creation_is_limited_to_10_a_minute_per_customer()
    {
        $user = User::factory()->create();
        $request = Request::create('/orders', 'POST');
        $request->setUserResolver(fn () => $user);

        $limit = $this->resolve('orders', $request);

        $this->assertSame(10, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame($user->id, $limit->key);
    }

    private function resolve(string $name, Request $request): Limit
    {
        $limiter = RateLimiter::limiter($name);
        $this->assertNotNull($limiter, "The [{$name}] rate limiter is not defined.");

        $limit = $limiter($request);
        $this->assertInstanceOf(Limit::class, $limit);

        return $limit;
    }
}
