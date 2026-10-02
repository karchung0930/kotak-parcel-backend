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
        Schema::create('delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('driver_id')->constrained('users');
            $table->string('outcome', 16);
            $table->string('recipient_name')->nullable();
            // Proof of delivery photo, stored on the private "local" disk.
            $table->string('photo_path')->nullable();
            $table->string('failure_reason', 32)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('attempted_at');
            $table->timestamps();

            $table->index(['order_id', 'outcome']);
            // A driver's attempts during a day.
            $table->index(['driver_id', 'attempted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_attempts');
    }
};
