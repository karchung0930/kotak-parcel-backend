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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            // Unique: an order can only ever be paid once.
            $table->foreignId('order_id')->unique()->constrained();
            $table->unsignedInteger('amount_sen');
            $table->string('method', 16);
            // Card terminal approval code only. Card numbers and CVVs are never stored.
            $table->string('reference', 64)->nullable();
            $table->string('receipt_number', 32)->unique();
            $table->foreignId('received_by')->constrained('users');
            $table->foreignId('branch_id')->constrained();
            $table->timestamp('paid_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
