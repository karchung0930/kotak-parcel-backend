<?php

namespace Tests\Feature\Http;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Illuminate\View\View;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class ErrorPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The branded error page is only used outside local development and tests.
     */
    private function inProduction(): void
    {
        $this->app['env'] = 'production';
    }

    public function test_an_unknown_page_renders_the_branded_not_found_page()
    {
        $this->inProduction();

        $this->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Error')
                ->where('status', 404));
    }

    public function test_a_forbidden_page_renders_the_branded_error_page()
    {
        $this->inProduction();

        $this->actingAs(User::factory()->customer()->create())
            ->get(route('admin.dispatch'))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Error')
                ->where('status', 403));
    }

    public function test_a_server_error_renders_the_branded_error_page_without_details()
    {
        $this->inProduction();

        Route::middleware('web')->get('/_test/server-error', function () {
            throw new RuntimeException('Secret failure details');
        });

        $this->get('/_test/server-error')
            ->assertInternalServerError()
            ->assertDontSee('Secret failure details')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Error')
                ->where('status', 500));
    }

    public function test_json_requests_keep_their_json_errors()
    {
        $this->inProduction();

        $this->getJson('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    public function test_other_statuses_keep_the_default_response()
    {
        $this->inProduction();

        Route::middleware('web')->get('/_test/teapot', fn () => abort(418));

        $response = $this->get('/_test/teapot');

        $response->assertStatus(418);
        $this->assertNotBrandedErrorPage($response);
    }

    public function test_local_development_keeps_the_detailed_error_screens()
    {
        $response = $this->get('/this-page-does-not-exist');

        $response->assertNotFound();
        $this->assertNotBrandedErrorPage($response);
    }

    private function assertNotBrandedErrorPage(TestResponse $response): void
    {
        $page = $response->original instanceof View ? ($response->original->getData()['page'] ?? null) : null;

        $this->assertNotSame('Error', $page['component'] ?? null);
    }
}
