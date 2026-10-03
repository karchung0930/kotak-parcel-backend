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
        // A group of states priced alike, e.g. "Peninsular Malaysia".
        Schema::create('rate_card_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_card_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name', 60);
            // App\Enums\MalaysianState values; each state is in one zone of a published card.
            $table->json('states');

            $table->unique(['rate_card_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rate_card_zones');
    }
};
