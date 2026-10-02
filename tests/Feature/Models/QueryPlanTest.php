<?php

namespace Tests\Feature\Models;

use App\Models\DeliveryAttempt;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The busiest pages' queries are answered from an index instead of scanning
 * and sorting the table. Checked with SQLite's query planner.
 */
class QueryPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Query plans are checked on SQLite.');
        }
    }

    public function test_the_counters_recent_parcels_come_from_an_index()
    {
        $recent = fn (?int $branchId) => Order::query()
            ->whereNotNull('dropped_off_at')
            ->when($branchId, fn ($orders, int $branchId) => $orders->where('branch_id', $branchId))
            ->latest('dropped_off_at')
            ->limit(10);

        $this->assertUsesIndexWithoutSorting('orders_branch_id_dropped_off_at_index', $recent(1));
        $this->assertUsesIndexWithoutSorting('orders_dropped_off_at_index', $recent(null));
    }

    public function test_a_drivers_jobs_for_a_day_use_both_columns_of_the_index()
    {
        $driver = User::factory()->driver()->make(['id' => 1]);

        $plan = $this->queryPlan(Order::query()->forDriver($driver)->scheduledOn(CarbonImmutable::parse('2026-09-29')));

        $this->assertStringContainsString(
            'orders_driver_id_scheduled_for_index (driver_id=? AND scheduled_for>? AND scheduled_for<?)', $plan,
        );
    }

    public function test_a_drivers_attempts_during_a_day_come_from_an_index()
    {
        $plan = $this->queryPlan(DeliveryAttempt::query()
            ->where('driver_id', 1)
            ->whereBetween('attempted_at', [now()->startOfDay(), now()->endOfDay()]));

        $this->assertStringContainsString('delivery_attempts_driver_id_attempted_at_index', $plan);
    }

    /**
     * Assert that the query reads the given index and needs no separate sort.
     */
    private function assertUsesIndexWithoutSorting(string $index, Builder $query): void
    {
        $plan = $this->queryPlan($query);

        $this->assertStringContainsString($index, $plan);
        $this->assertStringNotContainsString('TEMP B-TREE', $plan);
    }

    /**
     * Get SQLite's plan for the query as one line of text.
     */
    private function queryPlan(Builder $query): string
    {
        $rows = DB::select('EXPLAIN QUERY PLAN '.$query->toSql(), $query->getBindings());

        return collect($rows)->pluck('detail')->implode(' | ');
    }
}
