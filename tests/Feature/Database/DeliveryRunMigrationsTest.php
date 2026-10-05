<?php

namespace Tests\Feature\Database;

use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Changing a table commits the open transaction on MySQL, so this test
 * migrates its own database instead of running inside RefreshDatabase's
 * transaction.
 */
class DeliveryRunMigrationsTest extends TestCase
{
    use DatabaseMigrations;

    public function test_the_run_migrations_roll_back_then_number_and_date_the_open_runs_again()
    {
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Kuala_Lumpur'));
        $driver = User::factory()->driver()->create();
        $far = Order::factory()->assigned($driver)->create(['postcode' => '59000', 'scheduled_for' => '2026-10-05', 'route_position' => 1]);
        $near = Order::factory()->pickedUp($driver)->create(['postcode' => '47300', 'scheduled_for' => '2026-10-05', 'route_position' => 2]);
        $overdue = Order::factory()->pickedUp($driver)->create(['postcode' => '68000', 'scheduled_for' => '2026-10-04', 'route_position' => 4]);
        $delivered = Order::factory()->delivered($driver)->create();

        $positions = require database_path('migrations/2026_10_05_000002_add_route_position_to_orders_table.php');
        $dates = require database_path('migrations/2026_10_05_000003_add_route_date_to_orders_table.php');
        $this->assertInstanceOf(Migration::class, $positions);
        $this->assertInstanceOf(Migration::class, $dates);

        $dates->down();
        $positions->down();
        $this->assertFalse(Schema::hasColumn('orders', 'route_date'));
        $this->assertFalse(Schema::hasColumn('orders', 'route_position'));

        $positions->up();
        $dates->up();
        $this->assertTrue(Schema::hasColumn('orders', 'route_position'));
        $this->assertTrue(Schema::hasColumn('orders', 'route_date'));

        // Numbered as My jobs listed them before drivers set the order (by
        // postcode), each on its scheduled day's run; a finished job is on none.
        $runs = collect([$near, $far, $overdue, $delivered])
            ->map(fn (Order $order) => $order->refresh())
            ->map(fn (Order $order) => [$order->route_position, $order->route_date?->toDateString()])
            ->all();
        $this->assertSame([[1, '2026-10-05'], [2, '2026-10-05'], [1, '2026-10-04'], [null, null]], $runs);

        // So today's list keeps its order: the job carried over first.
        $this->assertSame(
            [$overdue->id, $near->id, $far->id],
            Order::query()->jobListFor($driver, CarbonImmutable::parse('2026-10-05'))->pluck('id')->all(),
        );
    }
}
