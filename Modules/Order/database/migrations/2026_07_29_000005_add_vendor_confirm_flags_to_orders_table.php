<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'can_confirm_pickup_from_driver')) {
                $table->boolean('can_confirm_pickup_from_driver')->default(false)->after('client_delivery_handoff_at');
            }

            if (! Schema::hasColumn('orders', 'can_confirm_handover_to_delivery')) {
                $table->boolean('can_confirm_handover_to_delivery')->default(false)->after('can_confirm_pickup_from_driver');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['can_confirm_pickup_from_driver', 'can_confirm_handover_to_delivery'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
