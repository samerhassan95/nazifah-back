<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes for common listing/filter queries (laundry by category, branch lookups).
     */
    public function up(): void
    {
        if (Schema::hasTable('services') && ! Schema::hasIndex('services', 'services_category_id_is_active_order_index')) {
            Schema::table('services', function (Blueprint $table) {
                $table->index(['category_id', 'is_active', 'order'], 'services_category_id_is_active_order_index');
            });
        }

        if (Schema::hasTable('branches') && ! Schema::hasIndex('branches', 'branches_vendor_id_is_active_rating_index')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->index(['vendor_id', 'is_active', 'rating'], 'branches_vendor_id_is_active_rating_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('services') && Schema::hasIndex('services', 'services_category_id_is_active_order_index')) {
            Schema::table('services', function (Blueprint $table) {
                $table->dropIndex('services_category_id_is_active_order_index');
            });
        }

        if (Schema::hasTable('branches') && Schema::hasIndex('branches', 'branches_vendor_id_is_active_rating_index')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->dropIndex('branches_vendor_id_is_active_rating_index');
            });
        }
    }
};
