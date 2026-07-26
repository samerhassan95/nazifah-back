<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staging table for an order total increase produced by an edit that adds
     * items to a gateway-paid order. The new total is NOT applied to the order
     * until the customer actually pays the surcharge (in the payment callback).
     * If they never pay, the order total stays at its original value.
     */
    public function up(): void
    {
        Schema::create('order_modification_intents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->decimal('delta_amount', 10, 2);          // amount the customer must pay
            $table->decimal('new_total', 10, 2);             // staged total_amount
            $table->decimal('new_final_amount', 10, 2);      // staged final_amount
            // Full staged pricing snapshot (discount/tax/delivery/distance) so the
            // order stays self-consistent when the intent is applied on payment.
            $table->json('staged_pricing')->nullable();
            $table->enum('status', ['pending', 'resolved', 'expired'])->default('pending');
            $table->unsignedBigInteger('payment_transaction_id')->nullable(); // filled on success
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index('payment_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_modification_intents');
    }
};
