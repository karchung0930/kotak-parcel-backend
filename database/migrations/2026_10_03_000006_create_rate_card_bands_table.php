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
        // The price of a route's parcels up to a weight, e.g. up to 2 kg for RM 13.00.
        Schema::create('rate_card_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_card_route_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('max_weight_g');
            $table->unsignedInteger('price_sen');

            $table->unique(['rate_card_route_id', 'max_weight_g']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rate_card_bands');
    }
};
