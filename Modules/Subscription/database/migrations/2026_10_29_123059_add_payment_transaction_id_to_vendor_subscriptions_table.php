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
        Schema::table('vendor_subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('vendor_subscriptions', 'payment_transaction_id')) {
                // Nullable: only set for subscriptions paid through the gateway
                // (card/etc). Unique so the same settled payment can never
                // activate two subscription rows under a race.
                $table->unsignedBigInteger('payment_transaction_id')->nullable()->unique()->after('subscription_plan_id');
                $table->foreign('payment_transaction_id')->references('id')->on('payment_transactions')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('vendor_subscriptions', 'payment_transaction_id')) {
                $table->dropForeign(['payment_transaction_id']);
                $table->dropColumn('payment_transaction_id');
            }
        });
    }
};
