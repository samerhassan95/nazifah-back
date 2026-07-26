<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add pickup_driver_id and delivery_driver_id to orders for two-driver flow
     * (استلام / تسليم). driver_id remains the "current" assigned driver.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'pickup_driver_id')) {
                $table->foreignId('pickup_driver_id')->nullable()->after('driver_id')->constrained('drivers')->onDelete('set null');
            }
            if (! Schema::hasColumn('orders', 'delivery_driver_id')) {
                $table->foreignId('delivery_driver_id')->nullable()->after('pickup_driver_id')->constrained('drivers')->onDelete('set null');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'pickup_driver_id')) {
                $table->dropForeign(['pickup_driver_id']);
            }
            if (Schema::hasColumn('orders', 'delivery_driver_id')) {
                $table->dropForeign(['delivery_driver_id']);
            }
        });
    }
};
