<?php

namespace Tests\Feature\Resources;

use App\Http\Resources\TrackingResource;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackingResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_tracking_shows_progress_without_personal_data()
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
            'address_line1' => 'No. 12, Jalan Datuk Sulaiman 1',
            'address_line2' => 'Taman Tun Dr Ismail',
            'city' => 'Kuala Lumpur',
            'postcode' => '60000',
        ]);

        $data = (new TrackingResource(Order::findOrFail($order->id)))->resolve();
        $json = (string) json_encode($data);

        $this->assertSame('KT-7Q4M92XD', $data['tracking_number']);
        $this->assertSame(['value' => 'delivered', 'label' => 'Delivered'], $data['status']);
        $this->assertSame(['city' => 'Kuala Lumpur', 'postcode' => '60000'], $data['destination']);
        $this->assertCount(6, $data['events']);
        $this->assertSame(['status', 'description', 'city', 'created_at'], array_keys($data['events'][0]));

        foreach ([
            'Aisyah Rahman', 'aisyah@kotak.test', '+60123456789', 'Daniel Lim', '+60127788990',
            'Jalan Datuk Sulaiman', 'Taman Tun Dr Ismail', $order->driver?->name, $order->driver?->vehicle_plate,
        ] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $json);
        }

        foreach (['sender_name', 'receiver_name', 'phone', 'email', 'address', 'price', 'note', 'customer', 'driver', 'photo'] as $key) {
            $this->assertStringNotContainsString("\"{$key}", $json);
        }
    }

    public function test_events_show_the_city_of_the_branch_where_they_happened()
    {
        $branch = Branch::factory()->create(['city' => 'Petaling Jaya']);
        $order = Order::factory()->create();
        $order->statusEvents()->create(['from_status' => 'created', 'to_status' => 'dropped_off', 'branch_id' => $branch->id]);

        $data = (new TrackingResource($order->fresh()))->resolve();

        $this->assertNull($data['events'][0]['city']);
        $this->assertSame('Petaling Jaya', $data['events'][1]['city']);
    }
}
