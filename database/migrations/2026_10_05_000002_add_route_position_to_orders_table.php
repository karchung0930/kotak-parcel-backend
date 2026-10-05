<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // The stop's place in its driver's run for its day (1, 2, 3…);
            // null while the parcel is on nobody's run.
            $table->unsignedSmallInteger('route_position')->nullable()->after('scheduled_for');
        });

        $this->numberOpenRuns();
    }

    /**
     * Number the stops of every open run in the order My jobs listed them
     * until now, by postcode within each driver's day, so no driver finds
     * their list reshuffled.
     */
    public function numberOpenRuns(): void
    {
        $run = null;
        $position = 0;

        DB::table('orders')
            ->whereIn('status', ['assigned', 'picked_up'])
            ->whereNotNull('driver_id')
            ->whereNotNull('scheduled_for')
            ->orderBy('driver_id')
            ->orderBy('scheduled_for')
            ->orderBy('postcode')
            ->orderBy('id')
            ->select(['id', 'driver_id', 'scheduled_for'])
            ->each(function (object $order) use (&$run, &$position): void {
                $key = "{$order->driver_id}|{$order->scheduled_for}";
                $position = $key === $run ? $position + 1 : 1;
                $run = $key;

                DB::table('orders')->where('id', $order->id)->update(['route_position' => $position]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('route_position');
        });
    }
};
