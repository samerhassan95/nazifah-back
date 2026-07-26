<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->unique()->constrained('drivers')->cascadeOnDelete();
            $table->decimal('balance', 10, 2)->default(0);
            $table->decimal('hold_amount', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('delivery_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_wallet_id')->constrained('delivery_wallets')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('type'); // credit, debit, hold, release
            $table->decimal('amount', 10, 2);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['delivery_wallet_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_wallet_transactions');
        Schema::dropIfExists('delivery_wallets');
    }
};
