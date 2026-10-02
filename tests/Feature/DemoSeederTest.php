<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Rules\MalaysianPhone;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_consistent_demo_data_in_every_status()
    {
        Notification::fake();
        Storage::fake('local');

        $this->seed(DemoSeeder::class);

        $this->assertSame(6, Branch::count());
        $this->assertSame(1, User::where('role', 'admin')->count());
        $this->assertSame(4, User::where('role', 'driver')->count());
        $this->assertSame(3, User::where('role', 'customer')->count());
        $this->assertGreaterThanOrEqual(6, User::where('role', 'staff')->count());

        $statuses = Order::query()->distinct()->pluck('status')->map(fn (OrderStatus $status) => $status->value)->sort()->values()->all();
        $this->assertSame(collect(OrderStatus::cases())->map->value->sort()->values()->all(), $statuses);

        $sample = Order::byTrackingNumber('KT-7Q4M92XD')->with('customer')->firstOrFail();
        $this->assertSame('Aisyah Rahman', $sample->customer->name);
        $this->assertSame('Daniel Lim', $sample->receiver_name);
        $this->assertSame('60000', $sample->postcode);
        $this->assertSame(6000, $sample->chargeable_weight_g);
        $this->assertSame(1800, $sample->final_price_sen);

        $this->assertSame(OrderStatus::Delivered, Order::byTrackingNumber('KT-00000003')->firstOrFail()->status);
        $this->assertSame(OrderStatus::Cancelled, Order::byTrackingNumber('KT-00000021')->firstOrFail()->status);

        // Every paid order has exactly one payment for its final price.
        Order::query()->whereNotNull('paid_at')->with('payment')->each(function (Order $order) {
            $this->assertSame($order->final_price_sen, $order->payment?->amount_sen);
        });

        // Nothing in the demo timeline happens in the future.
        $this->assertSame(0, Order::where('updated_at', '>', now())->count());
    }

    public function test_seeded_phone_numbers_pass_the_rules_the_forms_use()
    {
        Notification::fake();
        Storage::fake('local');

        $this->seed(DemoSeeder::class);

        $passes = fn (?string $phone, MalaysianPhone $rule) => Validator::make(['phone' => $phone], ['phone' => ['required', $rule]])->passes();

        User::query()->each(function (User $user) use ($passes) {
            $this->assertTrue($passes($user->phone, MalaysianPhone::mobile()), "{$user->name}: {$user->phone}");
        });

        Order::query()->each(function (Order $order) use ($passes) {
            $this->assertTrue($passes($order->sender_phone, MalaysianPhone::mobile()), "{$order->tracking_number}: {$order->sender_phone}");
            $this->assertTrue($passes($order->receiver_phone, MalaysianPhone::mobileOrLandline()), "{$order->tracking_number}: {$order->receiver_phone}");
        });
    }

    public function test_it_refuses_to_run_in_production()
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);

        $this->app->make(DemoSeeder::class)->run();
    }
}
