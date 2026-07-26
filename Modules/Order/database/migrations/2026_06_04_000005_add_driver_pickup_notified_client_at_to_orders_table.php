<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'driver_pickup_notified_client_at')) {
                $table->timestamp('driver_pickup_notified_client_at')->nullable()->after('client_visit_confirmed_at');
            }
            if (! Schema::hasColumn('orders', 'client_pickup_visit_confirmed_at')) {
                $table->timestamp('client_pickup_visit_confirmed_at')->nullable()->after('driver_pickup_notified_client_at');
            }
            if (! Schema::hasColumn('orders', 'client_delivery_visit_confirmed_at')) {
                $table->timestamp('client_delivery_visit_confirmed_at')->nullable()->after('client_pickup_visit_confirmed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach ([
                'driver_pickup_notified_client_at',
                'client_pickup_visit_confirmed_at',
                'client_delivery_visit_confirmed_at',
            ] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
