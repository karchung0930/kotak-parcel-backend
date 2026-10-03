<?php

namespace Tests\Feature\Database;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DropOffDeadlineMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_already_waiting_keep_the_fourteen_day_limit_they_were_placed_under()
    {
        // Placed at 23:30 on 3 October in Kuala Lumpur (15:30 UTC).
        $waiting = Order::factory()->create(['created_at' => CarbonImmutable::parse('2026-10-03 15:30:00', 'UTC')]);
        $paid = Order::factory()->paid()->create();

        // The new columns can be added to a table that already has orders, and
        // start empty. MySQL commits the test's transaction on any column
        // change, so only the step that fills them in runs again here.
        $columns = collect(Schema::getColumns('orders'))->keyBy('name');
        $this->assertTrue($columns['drop_off_deadline']['nullable']);
        $this->assertTrue($columns['drop_off_reminded_at']['nullable']);
        Order::query()->update(['drop_off_deadline' => null]);

        $migration = require database_path('migrations/2026_10_03_000002_add_drop_off_deadline_to_orders_table.php');
        $this->assertInstanceOf(Migration::class, $migration);
        $migration->keepPreviousLimitForWaitingOrders();

        $this->assertSame('2026-10-17', $waiting->fresh()?->dropOffDeadline()?->toDateString());
        $this->assertNull($paid->fresh()?->drop_off_deadline);
    }
}
