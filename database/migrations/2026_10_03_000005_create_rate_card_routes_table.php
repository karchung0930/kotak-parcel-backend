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
        // The prices from one zone to another. Directional: Sabah to
        // Peninsular Malaysia is its own route.
        Schema::create('rate_card_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('origin_zone_id')->constrained('rate_card_zones')->cascadeOnDelete();
            $table->foreignId('destination_zone_id')->constrained('rate_card_zones')->cascadeOnDelete();
            // In sen, for each started kg above the highest band. Drafts may leave it empty.
            $table->unsignedInteger('extra_kg_sen')->nullable();

            // Named explicitly: the generated name is longer than MySQL's 64 characters.
            $table->unique(['rate_card_id', 'origin_zone_id', 'destination_zone_id'], 'rate_card_routes_card_origin_destination_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rate_card_routes');
    }
};
