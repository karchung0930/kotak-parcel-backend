<?php

namespace Tests\Feature\Public;

use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TrackingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pages are checked through their Inertia props, not the built assets.
        $this->withoutVite();
    }

    public function test_the_page_opens_without_a_tracking_number()
    {
        $this->get(route('track'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('track/Show')
                ->where('query', null)
                ->where('result', null));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function trackingNumberFormats(): array
    {
        return [
            'as displayed' => ['KT-7Q4M92XD'],
            'without the hyphen' => ['KT7Q4M92XD'],
            'lowercase' => ['kt-7q4m92xd'],
            'lowercase without the hyphen' => ['kt7q4m92xd'],
            'with spaces' => [' KT 7Q4M 92XD '],
            'copied from an email' => ["KT\u{2011}7Q4M92XD"],
        ];
    }

    #[DataProvider('trackingNumberFormats')]
    public function test_a_parcel_is_found_by_its_tracking_number_in_any_format(string $number)
    {
        $branch = Branch::factory()->create(['name' => 'Petaling Jaya - SS2', 'city' => 'Petaling Jaya']);
        Order::factory()->for($branch)->paid()->create([
            'tracking_number' => 'KT7Q4M92XD',
            'city' => 'Kuala Lumpur',
            'postcode' => '60000',
        ]);

        $this->get(route('track', ['number' => $number]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('track/Show')
                ->where('result.tracking_number', 'KT-7Q4M92XD')
                ->where('result.status', ['value' => 'paid', 'label' => 'Paid'])
                ->where('result.destination', ['city' => 'Kuala Lumpur', 'postcode' => '60000'])
                ->where('result.branch', ['name' => 'Petaling Jaya - SS2', 'city' => 'Petaling Jaya'])
                ->has('result.events', 3)
                ->where('result.events.0.status.value', 'created')
                ->where('result.events.2.status.value', 'paid'));
    }

    public function test_an_unknown_tracking_number_is_not_found()
    {
        Order::factory()->create(['tracking_number' => 'KT7Q4M92XD']);

        $this->get(route('track', ['number' => 'KT-7Q4M92XE']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('track/Show')
                ->where('query', 'KT-7Q4M92XE')
                ->where('result', null));
    }

    /**
     * @return array<string, array{array<string, mixed>, string|null}>
     */
    public static function malformedInput(): array
    {
        return [
            'not a tracking number' => [['number' => 'hello'], 'hello'],
            'wildcards' => [['number' => 'KT-%'], 'KT-%'],
            'too long' => [['number' => str_repeat('K', 200)], str_repeat('K', 32)],
            'an array' => [['number' => ['KT7Q4M92XD']], null],
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    #[DataProvider('malformedInput')]
    public function test_malformed_input_finds_nothing(array $parameters, ?string $query)
    {
        Order::factory()->create(['tracking_number' => 'KT7Q4M92XD']);

        $this->get(route('track', $parameters))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('query', $query)
                ->where('result', null));
    }

    public function test_the_public_result_contains_no_personal_data()
    {
        $customer = User::factory()->create([
            'name' => 'Aisyah Rahman',
            'email' => 'aisyah@kotak.test',
            'phone' => '+60123456789',
        ]);
        $order = Order::factory()->for($customer, 'customer')->delivered()->create([
            'tracking_number' => 'KT7Q4M92XD',
            'receiver_name' => 'Daniel Lim',
            'receiver_phone' => '+60127788990',
            'receiver_email' => 'daniel.lim@example.com',
            'address_line1' => 'No. 12, Jalan Datuk Sulaiman 1',
            'address_line2' => 'Taman Tun Dr Ismail',
            'item_name' => 'Ceramic dinner set',
            'postcode' => '60000',
        ]);
        $order->deliveryAttempts()->update(['recipient_name' => 'Mei Ling', 'photo_path' => 'pod/1/photo.png']);
        $driver = $order->driver()->firstOrFail();

        $response = $this->get(route('track', ['number' => 'KT-7Q4M92XD']))->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertSame('KT-7Q4M92XD', $props['result']['tracking_number']);

        $json = (string) json_encode($props, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach ([
            'Aisyah Rahman', 'aisyah@kotak.test', '+60123456789', 'Daniel Lim', '+60127788990', 'daniel.lim@example.com',
            'Jalan Datuk Sulaiman', 'Taman Tun Dr Ismail', 'Ceramic dinner set', 'Mei Ling', 'pod/1/photo.png',
            $driver->name, (string) $driver->vehicle_plate,
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }

        foreach (['receiver_name', 'receiver_phone', 'sender_name', 'sender_phone', 'address_line1', 'address_line2', 'email', 'price', 'photo'] as $key) {
            $this->assertStringNotContainsString("\"{$key}", $json);
        }
    }

    public function test_tracking_lookups_are_rate_limited_per_visitor()
    {
        for ($i = 0; $i < 30; $i++) {
            $this->get(route('track', ['number' => 'KT-7Q4M92XD']))->assertOk();
        }

        $this->get(route('track', ['number' => 'KT-7Q4M92XD']))->assertTooManyRequests();

        // Another visitor has their own allowance.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->get(route('track', ['number' => 'KT-7Q4M92XD']))
            ->assertOk();
    }
}
