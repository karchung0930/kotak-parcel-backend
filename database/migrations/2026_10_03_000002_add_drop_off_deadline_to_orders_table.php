<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The limit before it became a site setting. Orders already waiting keep it.
     */
    private const PREVIOUS_UNCLAIMED_ORDER_DAYS = 14;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // The last Malaysian calendar day to drop the parcel off, fixed when the order is placed.
            $table->date('drop_off_deadline')->nullable()->after('scheduled_for');
            // When the customer was reminded to drop the parcel off; set once, so nobody is reminded twice.
            $table->timestamp('drop_off_reminded_at')->nullable()->after('drop_off_deadline');
        });

        $this->keepPreviousLimitForWaitingOrders();
    }

    /**
     * Fill in the deadline of the orders waiting for drop-off. They were
     * placed under the old limit, so they keep it: nothing is cancelled
     * earlier than it would have been.
     */
    public function keepPreviousLimitForWaitingOrders(): void
    {
        DB::table('orders')
            ->where('status', 'created')
            ->select(['id', 'created_at'])
            ->chunkById(500, function ($orders): void {
                foreach ($orders as $order) {
                    $deadline = CarbonImmutable::parse($order->created_at ?? 'now', 'UTC')
                        ->setTimezone(config()->string('kotak.timezone'))
                        ->addDays(self::PREVIOUS_UNCLAIMED_ORDER_DAYS)
                        ->toDateString();

                    DB::table('orders')->where('id', $order->id)->update(['drop_off_deadline' => $deadline]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['drop_off_deadline', 'drop_off_reminded_at']);
        });
    }
};
