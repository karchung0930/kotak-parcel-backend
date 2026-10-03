<?php

namespace Tests\Feature\Database;

use App\Enums\MalaysianState;
use App\Enums\RateCardStatus;
use App\Models\Order;
use App\Models\RateCard;
use App\Support\PriceCalculator;
use App\Support\RateCards;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StandardRateCardMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_rate_card_prices_exactly_like_the_old_formula()
    {
        $card = app(RateCards::class)->current();
        $pricing = app(PriceCalculator::class);
        $states = MalaysianState::cases();
        $boxes = [[1, 1, 1], [10, 10, 10], [20, 15, 5], [33, 27, 19], [40, 30, 25], [60, 40, 30], [100, 60, 50], [150, 150, 150]];
        $checked = 0;

        // RM 8.00 for the first kg and RM 2.00 for each further started kg, on
        // the greater of the actual weight and L x W x H / 5000, as before.
        $oldPrice = fn (int $grams): int => 800 + max(0, intdiv($grams + 999, 1000) - 1) * 200;
        $oldVolumetric = fn (int $l, int $w, int $h): int => intdiv($l * $w * $h * 1000 + 4999, 5000);

        foreach ([1, 250, 999, 1000, 1001, 1500, 2000, 4200, 5100, 9999, 12500, 29999, 30000] as $i => $grams) {
            foreach ($boxes as $j => [$length, $width, $height]) {
                $origin = $states[($i + $j) % count($states)];
                $destination = $states[($i * 3 + $j) % count($states)];
                $quote = $pricing->quote($card, $origin, $destination, $grams, $length, $width, $height);
                $chargeable = max($grams, $oldVolumetric($length, $width, $height));

                $this->assertSame($chargeable, $quote->chargeableG, "{$grams} g in {$length} x {$width} x {$height} cm");
                $this->assertSame($oldPrice($chargeable), $quote->priceSen, "{$grams} g in {$length} x {$width} x {$height} cm");
                $checked++;
            }
        }

        $this->assertSame(104, $checked);
    }

    public function test_it_publishes_the_standard_rates_before_the_earliest_order_and_backfills_every_order()
    {
        $earliest = CarbonImmutable::parse('2026-09-01 02:00:00', 'UTC');
        $waiting = Order::factory()->create(['created_at' => $earliest]);
        $paid = Order::factory()->paid()->create();

        $migration = require database_path('migrations/2026_10_03_000008_create_standard_rate_card.php');
        $this->assertInstanceOf(Migration::class, $migration);
        $migration->down();

        $this->assertSame(0, RateCard::query()->count());
        $this->assertNull($waiting->fresh()?->estimated_rate_card_id);

        $migration->up();

        $card = RateCard::query()->with(['zones', 'routes.bands'])->sole();
        $this->assertSame('Standard rates', $card->name);
        $this->assertSame(RateCardStatus::Published, $card->status);
        $this->assertSame(5000, $card->volumetric_divisor);
        $this->assertSame($earliest->toDateTimeString(), $card->effective_from?->toDateTimeString());
        $this->assertSame(array_column(MalaysianState::cases(), 'value'), $card->zones->sole()->states);
        $this->assertSame(200, $card->routes->sole()->extra_kg_sen);
        $this->assertSame([1000, 800], [$card->routes->sole()->bands->sole()->max_weight_g, $card->routes->sole()->bands->sole()->price_sen]);

        $this->assertSame($card->id, $waiting->fresh()?->estimated_rate_card_id);
        $this->assertNull($waiting->fresh()?->final_rate_card_id);
        $this->assertSame([$card->id, $card->id], [$paid->fresh()?->estimated_rate_card_id, $paid->fresh()?->final_rate_card_id]);
    }

    public function test_without_orders_the_standard_rates_take_effect_a_minute_before_the_migration()
    {
        $this->freezeSecond();
        $migration = require database_path('migrations/2026_10_03_000008_create_standard_rate_card.php');
        $migration->down();
        $migration->up();

        $this->assertSame(now()->subMinute()->toDateTimeString(), RateCard::query()->sole()->effective_from?->toDateTimeString());
    }
}
