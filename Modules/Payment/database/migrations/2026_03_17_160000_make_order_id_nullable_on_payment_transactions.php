<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wallet deposits and other non-order payments have no order_id.
     */
    public function up(): void
    {
        Schema::table('payment_transactions', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('payment_transactions')->whereNull('order_id')->exists()) {
            throw new \RuntimeException(
                'Rollback blocked: rows with null order_id exist (e.g. wallet deposits). Remove or assign order_id first.'
            );
        }
        Schema::table('payment_transactions', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable(false)->change();
        });
    }
};
