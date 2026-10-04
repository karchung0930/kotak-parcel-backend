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
        // A spreadsheet of prices on its way to a draft rate card: uploaded,
        // read, mapped to the base card's zones and checked. Nothing is
        // written to the rate card tables until the admin creates the draft.
        Schema::create('rate_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The card whose zones and divisor the imported prices use.
            $table->foreignId('base_rate_card_id')->nullable()->constrained('rate_cards')->nullOnDelete();
            $table->string('original_name');
            // On the private disk; null once the daily clean-up has deleted the file.
            $table->string('path')->nullable();
            $table->string('sheet', 100)->nullable();
            $table->string('status', 16)->default('uploaded');
            $table->string('layout', 8)->nullable();
            $table->json('mapping')->nullable();
            $table->json('errors')->nullable();
            $table->json('preview')->nullable();
            $table->json('summary')->nullable();
            // The draft made from the file.
            $table->foreignId('rate_card_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            // The daily clean-up looks for old files.
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rate_imports');
    }
};
