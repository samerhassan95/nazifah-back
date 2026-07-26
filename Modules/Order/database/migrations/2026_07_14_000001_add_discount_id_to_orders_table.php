<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'discount_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreignId('discount_id')
                    ->nullable()
                    ->after('discount_amount')
                    ->constrained('discounts')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'discount_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropConstrainedForeignId('discount_id');
            });
        }
    }
};
