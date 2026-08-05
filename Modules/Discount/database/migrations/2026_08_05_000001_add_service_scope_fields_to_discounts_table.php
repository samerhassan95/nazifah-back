<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            if (! Schema::hasColumn('discounts', 'usage_condition')) {
                $table->string('usage_condition')->default('all')->after('discount_type');
            }
            if (! Schema::hasColumn('discounts', 'usage_service_ids')) {
                $table->json('usage_service_ids')->nullable()->after('usage_condition');
            }
            if (! Schema::hasColumn('discounts', 'application_scope')) {
                $table->string('application_scope')->default('order_total')->after('usage_service_ids');
            }
            if (! Schema::hasColumn('discounts', 'discount_service_ids')) {
                $table->json('discount_service_ids')->nullable()->after('application_scope');
            }
        });
    }

    public function down(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            $columns = [];

            foreach (['usage_condition', 'usage_service_ids', 'application_scope', 'discount_service_ids'] as $column) {
                if (Schema::hasColumn('discounts', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};

