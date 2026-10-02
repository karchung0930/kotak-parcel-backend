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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('tracking_number', 10)->unique();
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('branch_id')->constrained();
            $table->string('status', 32)->default('created')->index();

            // Sender snapshot, copied from the customer when the order is created.
            $table->string('sender_name');
            $table->string('sender_phone', 20);

            $table->string('receiver_name');
            $table->string('receiver_phone', 20);
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('city', 100);
            $table->string('state', 50);
            $table->string('postcode', 5);

            // Weights are grams and money is sen, always stored as integers.
            $table->string('item_name');
            $table->unsignedInteger('declared_weight_g');
            $table->unsignedSmallInteger('length_cm');
            $table->unsignedSmallInteger('width_cm');
            $table->unsignedSmallInteger('height_cm');
            $table->unsignedInteger('measured_weight_g')->nullable();
            $table->unsignedInteger('chargeable_weight_g');
            $table->unsignedInteger('estimated_price_sen');
            $table->unsignedInteger('final_price_sen')->nullable();

            $table->foreignId('driver_id')->nullable()->constrained('users');
            $table->date('scheduled_for')->nullable();

            $table->timestamp('dropped_off_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['driver_id', 'scheduled_for']);
            $table->index(['customer_id', 'created_at']);
            // The counter's "recently received" list, per branch and across branches.
            $table->index(['branch_id', 'dropped_off_at']);
            $table->index('dropped_off_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
