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
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->enum('type', ['credit', 'debit'])->comment('credit: money added, debit: money deducted');
            $table->decimal('amount', 10, 2);
            $table->string('payment_method')->nullable()->comment('card, apple_pay, google_pay, bank_transfer, etc.');
            $table->string('description')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('transaction_id')->nullable()->unique()->comment('External payment gateway transaction ID');
            $table->enum('status', ['pending', 'completed', 'failed', 'refunded'])->default('completed');
            $table->timestamps();

            // Indexes
            $table->index('client_id');
            $table->index('type');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
