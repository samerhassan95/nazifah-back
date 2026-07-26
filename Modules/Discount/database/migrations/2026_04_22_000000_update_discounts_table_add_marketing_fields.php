<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            if (! Schema::hasColumn('discounts', 'applicable_to')) {
                $table->string('applicable_to')->default('all');
            }
            if (! Schema::hasColumn('discounts', 'user_ids')) {
                $table->json('user_ids')->nullable();
            }
            if (! Schema::hasColumn('discounts', 'group_ids')) {
                $table->json('group_ids')->nullable();
            }
            if (! Schema::hasColumn('discounts', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('discounts', 'applicable_to')) {
                $columns[] = 'applicable_to';
            }
            if (Schema::hasColumn('discounts', 'user_ids')) {
                $columns[] = 'user_ids';
            }
            if (Schema::hasColumn('discounts', 'group_ids')) {
                $columns[] = 'group_ids';
            }

            if (! empty($columns)) {
                $table->dropColumn($columns);
            }

            if (Schema::hasColumn('discounts', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
