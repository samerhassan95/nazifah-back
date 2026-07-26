<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'wallet_hold_amount')) {
                $table->decimal('wallet_hold_amount', 10, 2)->default(0)->after('wallet_balance');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'wallet_hold_amount')) {
                $table->dropColumn('wallet_hold_amount');
            }
        });
    }
};
