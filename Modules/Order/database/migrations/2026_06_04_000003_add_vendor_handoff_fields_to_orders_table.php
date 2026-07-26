<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'vendor_pickup_received_at')) {
                $table->timestamp('vendor_pickup_received_at')->nullable();
            }
            if (! Schema::hasColumn('orders', 'vendor_delivery_ready_at')) {
                $table->timestamp('vendor_delivery_ready_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['vendor_pickup_received_at', 'vendor_delivery_ready_at'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
