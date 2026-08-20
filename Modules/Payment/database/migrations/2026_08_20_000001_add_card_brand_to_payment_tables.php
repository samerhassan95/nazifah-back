<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_transactions') && ! Schema::hasColumn('payment_transactions', 'card_brand')) {
            Schema::table('payment_transactions', function (Blueprint $table) {
                $table->string('card_brand', 32)->nullable()->after('payment_method')
                    ->comment('Network under a wallet method: visa, mastercard, mada');
            });
        }

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'card_brand')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('card_brand', 32)->nullable()->after('payment_method')
                    ->comment('Network under a wallet method: visa, mastercard, mada');
            });
        }

        if (Schema::hasTable('wallet_transactions') && ! Schema::hasColumn('wallet_transactions', 'card_brand')) {
            Schema::table('wallet_transactions', function (Blueprint $table) {
                $table->string('card_brand', 32)->nullable()->after('payment_method')
                    ->comment('Network under a wallet method: visa, mastercard, mada');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payment_transactions') && Schema::hasColumn('payment_transactions', 'card_brand')) {
            Schema::table('payment_transactions', function (Blueprint $table) {
                $table->dropColumn('card_brand');
            });
        }

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'card_brand')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('card_brand');
            });
        }

        if (Schema::hasTable('wallet_transactions') && Schema::hasColumn('wallet_transactions', 'card_brand')) {
            Schema::table('wallet_transactions', function (Blueprint $table) {
                $table->dropColumn('card_brand');
            });
        }
    }
};
