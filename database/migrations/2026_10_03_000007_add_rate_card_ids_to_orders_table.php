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
        Schema::table('orders', function (Blueprint $table) {
            // The rate cards that priced the online estimate and the final price at drop-off.
            $table->foreignId('estimated_rate_card_id')->nullable()->after('final_price_sen')->constrained('rate_cards');
            $table->foreignId('final_rate_card_id')->nullable()->after('estimated_rate_card_id')->constrained('rate_cards');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('final_rate_card_id');
            $table->dropConstrainedForeignId('estimated_rate_card_id');
        });
    }
};
