<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('customer')->index()->after('email');
            $table->string('phone', 20)->nullable()->after('role');
            $table->foreignId('branch_id')->nullable()->after('phone')->constrained()->nullOnDelete();
            $table->string('vehicle_plate', 16)->nullable()->after('branch_id');
            $table->boolean('is_active')->default(true)->after('vehicle_plate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropIndex(['role']);
            $table->dropColumn(['role', 'phone', 'vehicle_plate', 'is_active']);
        });
    }
};
