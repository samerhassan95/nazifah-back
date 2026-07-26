<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('payfort_token_name')->unique();
            $table->string('card_brand')->comment('visa, mastercard, mada');
            $table->string('card_holder_name')->nullable();
            $table->char('last_four', 4);
            $table->string('expiry_date')->nullable()->comment('MM/YY from PayFort');
            $table->boolean('is_default')->default(false);
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedBigInteger('source_payment_transaction_id')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'is_default']);
            $table->foreign('source_payment_transaction_id')
                ->references('id')
                ->on('payment_transactions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_cards');
    }
};
