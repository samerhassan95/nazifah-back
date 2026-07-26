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
        Schema::table('addresses', function (Blueprint $table) {

            // Add national_address field
            if (! Schema::hasColumn('addresses', 'national_address')) {
                $table->string('national_address')->nullable()->after('title');
            }

            // Modify existing columns to be nullable
            $table->string('title')->nullable()->change();
            $table->string('street_name')->nullable()->change();
            $table->string('building_number')->nullable()->change();
            $table->string('street_number')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            // Drop zone_id if exists
            if (Schema::hasColumn('addresses', 'zone_id')) {
                $table->dropForeign(['zone_id']);
                $table->dropColumn('zone_id');
            }

            // Drop national_address
            if (Schema::hasColumn('addresses', 'national_address')) {
                $table->dropColumn('national_address');
            }

            // Restore original column definitions (non-nullable)
            $table->string('title')->default('Address')->change();
            $table->string('street_name')->nullable(false)->change();
            $table->string('building_number')->nullable(false)->change();
            $table->string('street_number')->nullable(false)->change();
        });
    }
};
