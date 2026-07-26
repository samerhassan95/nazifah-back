<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'client_pickup_handoff_at')) {
                $table->timestamp('client_pickup_handoff_at')->nullable();
            }
            if (! Schema::hasColumn('orders', 'client_delivery_handoff_at')) {
                $table->timestamp('client_delivery_handoff_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $columns = ['client_pickup_handoff_at', 'client_delivery_handoff_at'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
