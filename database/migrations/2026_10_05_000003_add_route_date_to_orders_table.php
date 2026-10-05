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
            // The day of the run route_position belongs to: the scheduled day,
            // or today once the driver has put a job carried over from an
            // earlier day among today's stops; null while the parcel is on
            // nobody's run.
            $table->date('route_date')->nullable()->after('route_position');

            // A driver's open stops, by run: the reads that lock a run while
            // it changes (Order::nextRoutePosition(), MoveJob) find them here,
            // so they lock that driver's open jobs and not their whole history.
            $table->index(['driver_id', 'status', 'route_date']);
        });

        $this->dateOpenRuns();
    }

    /**
     * Give each open stop with a place the day of the run it was numbered
     * in: until now, a run was its scheduled day.
     */
    public function dateOpenRuns(): void
    {
        DB::table('orders')
            ->whereIn('status', ['assigned', 'picked_up'])
            ->whereNotNull('route_position')
            ->update(['route_date' => DB::raw('scheduled_for')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['driver_id', 'status', 'route_date']);
            $table->dropColumn('route_date');
        });
    }
};
