<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'vendor_client_delivery_handoff_at')) {
                $table->timestamp('vendor_client_delivery_handoff_at')->nullable()->after('vendor_delivery_ready_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'vendor_client_delivery_handoff_at')) {
                $table->dropColumn('vendor_client_delivery_handoff_at');
            }
        });
    }
};
