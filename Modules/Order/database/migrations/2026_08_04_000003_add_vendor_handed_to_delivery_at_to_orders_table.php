<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'vendor_handed_to_delivery_at')) {
                $table->timestamp('vendor_handed_to_delivery_at')->nullable()->after('can_confirm_handover_to_delivery');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'vendor_handed_to_delivery_at')) {
                $table->dropColumn('vendor_handed_to_delivery_at');
            }
        });
    }
};
