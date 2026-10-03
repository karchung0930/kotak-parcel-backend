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
        // One version of the prices. A published card is never changed: new
        // prices go into a draft copy, which takes over when it takes effect.
        Schema::create('rate_cards', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('status', 16)->default('draft');
            // Null for drafts. The published card that took effect last prices new orders.
            $table->timestamp('effective_from')->nullable();
            // Volumetric kg = length x width x height (cm) / divisor.
            $table->unsignedInteger('volumetric_divisor')->default(5000);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'effective_from']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rate_cards');
    }
};
