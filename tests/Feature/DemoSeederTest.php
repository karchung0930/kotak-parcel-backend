<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\RateCardPhase;
use App\Models\Branch;
use App\Models\Order;
use App\Models\RateCard;
use App\Models\User;
use App\Rules\MalaysianPhone;
use App\Support\RateCards;
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
        $this->assertSame(['KT00000024'], Order::expiredUnclaimed()->pluck('tracking_number')->all());

        // Every paid order has exactly one payment for its final price.
        Order::query()->whereNotNull('paid_at')->with('payment')->each(function (Order $order) {
            $this->assertSame($order->final_price_sen, $order->payment?->amount_sen);
        });

        // Nothing in the demo timeline happens in the future.
        $this->assertSame(0, Order::where('updated_at', '>', now())->count());

        // Two orders waiting for drop-off are due a reminder, and none has expired yet.
        $this->assertSame(['KT00000022', 'KT00000023'], Order::dueForDropOffReminder()->orderBy('id')->pluck('tracking_number')->all());
        $this->assertSame(0, Order::unclaimed()->count());
        $this->assertSame(0, Order::where('status', OrderStatus::Created)->whereNull('drop_off_deadline')->count());
        $this->assertSame(
            today('Asia/Kuala_Lumpur')->addDays(2)->toDateString(),
            Order::byTrackingNumber('KT-00000022')->firstOrFail()->dropOffDeadline()?->toDateString(),
        );
    }

    public function test_it_seeds_zone_rates_with_new_rates_scheduled_and_orders_priced_by_them()
    {
        Notification::fake();
        Storage::fake('local');

        $this->seed(DemoSeeder::class);

        $rateCards = app(RateCards::class);
        $this->assertSame('Malaysia zone rates', $rateCards->current()->name);
        $this->assertSame(['Peninsular Malaysia', 'Sabah & Labuan', 'Sarawak'], array_column($rateCards->current()->zones, 'name'));
        $standard = RateCard::query()->orderBy('id')->firstOrFail();
        $this->assertSame('Standard rates', $standard->name);
        $this->assertSame(RateCardPhase::Past, $standard->phase());
        // Moved back with the demo timeline: created, published and saved before the first demo order.
        $this->assertSame($standard->effective_from?->toDateTimeString(), $standard->created_at?->toDateTimeString());
        $this->assertGreaterThanOrEqual($standard->created_at?->toDateTimeString(), $standard->updated_at?->toDateTimeString());
        $this->assertLessThanOrEqual(Order::query()->min('created_at'), $standard->created_at?->toDateTimeString());

        // New East Malaysia rates from the 1st of next month, Malaysia time.
        $upcoming = $rateCards->upcoming();
        $nextMonth = now('Asia/Kuala_Lumpur')->startOfMonth()->addMonth();
        $this->assertSame('Rates from '.$nextMonth->format('j F Y'), $upcoming?->name);
        $this->assertSame($nextMonth->utc()->toIso8601ZuluString(), $upcoming->effectiveFrom?->toIso8601ZuluString());

        // Within Peninsular Malaysia the zone rates are the old flat rates, so the sample still costs RM 18.00.
        $sample = Order::byTrackingNumber('KT-7Q4M92XD')->firstOrFail();
        $this->assertSame(1800, $sample->final_price_sen);
        $this->assertSame($rateCards->current()->id, $sample->final_rate_card_id);

        // To Kuching: 1.75 kg by size, in the 2 kg band from Peninsular Malaysia to Sarawak.
        $kuching = Order::byTrackingNumber('KT-00000025')->firstOrFail();
        $this->assertSame(OrderStatus::Paid, $kuching->status);
        $this->assertSame(1750, $kuching->chargeable_weight_g);
        $this->assertSame(1600, $kuching->final_price_sen);
        $this->assertSame($rateCards->current()->id, $kuching->final_rate_card_id);

        // Older orders kept the Standard rates they were priced with.
        $this->assertSame('Standard rates', Order::byTrackingNumber('KT-00000012')->firstOrFail()->finalRateCard?->name);

        // Every order has its estimate's rates, and a final price's rates exactly when it has a final price.
        $this->assertSame(0, Order::query()->whereNull('estimated_rate_card_id')->count());
        $this->assertSame(
            Order::query()->whereNotNull('final_price_sen')->orderBy('id')->pluck('id')->all(),
            Order::query()->whereNotNull('final_rate_card_id')->orderBy('id')->pluck('id')->all(),
        );
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
