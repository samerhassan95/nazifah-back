<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIGRATION FOR VENDOR_WALLET_TRANSACTIONS TABLE
 * No consolidation needed - single migration file
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->onDelete('cascade');
            $table->enum('type', ['credit', 'debit'])->comment('credit: money added, debit: money deducted');
            $table->decimal('amount', 10, 2);
            $table->string('payment_method')->nullable()->comment('card, bank_transfer, etc.');
            $table->string('description')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('transaction_id')->nullable()->unique()->comment('External payment gateway transaction ID');
            $table->enum('status', ['pending', 'completed', 'failed', 'refunded'])->default('completed');
            $table->timestamps();

            $table->index('vendor_id');
            $table->index('type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_wallet_transactions');
    }
};
