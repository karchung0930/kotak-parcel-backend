<?php

namespace Tests\Feature\Models;

use App\Models\Branch;
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
 * and sorting the table. Checked with MySQL's EXPLAIN on a month of orders
 * and delivery attempts, where each query matches a small part of the table
 * as it does in production. (On a nearly empty table a full scan costs
 * nothing, so MySQL's choice there says little.)
 */
class QueryPlanTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The last day of the seeded month.
     */
    private const DAY = '2026-09-29';

    /**
     * @var list<int>
     */
    private array $branchIds;

    /**
     * @var list<int>
     */
    private array $driverIds;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Query plans are checked on MySQL, the production database.');
        }

        $this->seedAMonthOfWork();
    }

    public function test_the_counters_recent_parcels_come_from_an_index_in_order()
    {
        $recent = fn (?int $branchId) => Order::query()
            ->whereNotNull('dropped_off_at')
            ->when($branchId, fn ($orders, int $branchId) => $orders->where('branch_id', $branchId))
            ->latest('dropped_off_at')
            ->limit(10);

        $this->assertReadsIndexWithoutSorting('orders_branch_id_dropped_off_at_index', $recent($this->branchIds[0]));
        $this->assertReadsIndexWithoutSorting('orders_dropped_off_at_index', $recent(null));
    }

    public function test_a_drivers_jobs_for_a_day_use_both_columns_of_the_index()
    {
        $driver = User::query()->findOrFail($this->driverIds[0]);

        $plan = $this->plan(Order::query()->forDriver($driver)->scheduledOn(CarbonImmutable::parse(self::DAY)));

        $this->assertReadsIndex('orders_driver_id_scheduled_for_index', $plan);
        $this->assertSame(['driver_id', 'scheduled_for'], $plan['used_key_parts']);
    }

    public function test_a_drivers_attempts_during_a_day_use_both_columns_of_the_index()
    {
        $day = CarbonImmutable::parse(self::DAY, config('kotak.timezone'));

        $plan = $this->plan(DeliveryAttempt::query()
            ->where('driver_id', $this->driverIds[0])
            ->whereBetween('attempted_at', [$day->utc(), $day->endOfDay()->utc()]));

        $this->assertReadsIndex('delivery_attempts_driver_id_attempted_at_index', $plan);
        $this->assertSame(['driver_id', 'attempted_at'], $plan['used_key_parts']);
    }

    /**
     * Assert that the query reads the given index, in the order it needs, so
     * MySQL never sorts the rows itself.
     */
    private function assertReadsIndexWithoutSorting(string $index, Builder $query): void
    {
        $plan = $this->plan($query);

        $this->assertReadsIndex($index, $plan);
        $this->assertFalse($plan['using_filesort'], 'Sorted separately: '.json_encode($plan));
    }

    /**
     * Assert that the plan reads the table through the given index, not with a full scan.
     *
     * @param  array<string, mixed>  $plan
     */
    private function assertReadsIndex(string $index, array $plan): void
    {
        $this->assertSame($index, $plan['key'] ?? null, 'Plan: '.json_encode($plan));
        $this->assertNotSame('ALL', $plan['access_type']);
    }

    /**
     * Get MySQL's plan for the query's one table (EXPLAIN FORMAT=JSON), and
     * whether it sorts the rows after reading them.
     *
     * @return array<string, mixed>
     */
    private function plan(Builder $query): array
    {
        $explain = DB::selectOne('EXPLAIN FORMAT=JSON '.$query->toSql(), $query->getBindings());
        $block = json_decode($explain->EXPLAIN, true, flags: JSON_THROW_ON_ERROR)['query_block'];
        $ordering = $block['ordering_operation'] ?? [];

        return [
            ...($ordering['table'] ?? $block['table']),
            'using_filesort' => $ordering['using_filesort'] ?? false,
        ];
    }

    /**
     * A month at eight branches with eight drivers: 480 orders, each with a
     * delivery attempt, so every driver has two jobs a day and each branch
     * received 45 parcels. Only the indexed columns vary; the plans do not
     * depend on the rest.
     */
    private function seedAMonthOfWork(): void
    {
        $customer = User::factory()->create();
        $this->branchIds = Branch::factory()->count(8)->create()->modelKeys();
        $this->driverIds = User::factory()->driver()->count(8)->create()->modelKeys();

        $order = Order::factory()->make(['customer_id' => $customer->id, 'branch_id' => $this->branchIds[0]])->getAttributes();
        $last = CarbonImmutable::parse(self::DAY.' 10:00', config('kotak.timezone'));

        $orders = [];

        foreach (range(0, 479) as $i) {
            $day = $last->subDays($i % 30);

            $orders[] = [
                ...$order,
                'tracking_number' => sprintf('KT%08d', $i),
                'branch_id' => $this->branchIds[$i % 8],
                'driver_id' => $this->driverIds[intdiv($i, 30) % 8],
                'scheduled_for' => $day->toDateString(),
                // A quarter were never dropped off.
                'dropped_off_at' => $i % 4 === 0 ? null : $day->subDay()->addMinutes($i)->utc(),
            ];
        }

        DB::table('orders')->insert($orders);

        DB::table('delivery_attempts')->insert(DB::table('orders')->orderBy('id')->get(['id', 'driver_id', 'scheduled_for'])
            ->map(fn (object $order) => [
                'order_id' => $order->id,
                'driver_id' => $order->driver_id,
                'outcome' => 'delivered',
                'attempted_at' => CarbonImmutable::parse($order->scheduled_for.' 15:00', config('kotak.timezone'))->utc(),
            ])
            ->all());
    }
}
