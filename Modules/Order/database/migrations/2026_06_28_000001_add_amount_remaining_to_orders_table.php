<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-2 reconciliation: track how much an order still owes AFTER the
 * confirmation-time capture. When the amount due exceeds what could be captured
 * from the authorization holds + prepaid legs (Case B), the shortfall is recorded
 * here instead of silently rewriting final_amount down and hiding it. A positive
 * amount_remaining means the order is "awaiting remaining payment".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('amount_remaining', 10, 2)->default(0)->after('final_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('amount_remaining');
        });
    }
};
