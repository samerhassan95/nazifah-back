<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Split-payment ledger: an order can be settled by more than one leg, each
     * paid with a different method (e.g. part from wallet + part on a card, or
     * two separate card transactions). The order is "fully paid" once the sum of
     * its paid legs covers final_amount. Single-method orders work unchanged and
     * simply have a single leg (or none, for the legacy path).
     */
    public function up(): void
    {
        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            // The gateway/wallet transaction that settles this leg (nullable while pending).
            $table->unsignedBigInteger('payment_transaction_id')->nullable();
            // Set when the leg pays a surcharge produced by an order edit.
            $table->unsignedBigInteger('modification_intent_id')->nullable();
            $table->string('payment_method', 40);
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['pending', 'paid', 'failed', 'cancelled', 'refunded'])
                ->default('pending');
            // Order legs are settled in this order (gateway legs redirect one at a time).
            $table->unsignedSmallInteger('sequence')->default(0);
            // True when this leg pays for items added after the order was placed.
            $table->boolean('is_surcharge')->default(false);
            $table->json('meta')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index('payment_transaction_id');
            $table->index('modification_intent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');
    }
};
