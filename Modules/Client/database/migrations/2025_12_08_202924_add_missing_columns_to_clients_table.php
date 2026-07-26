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
        Schema::table('clients', function (Blueprint $table) {
            // Add missing columns
            if (! Schema::hasColumn('clients', 'image')) {
                $table->string('image')->nullable()->after('email');
            }

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'image',
                'street_name',
                'building_number',
                'street_number',
                'latitude',
                'longitude',
            ]);
        });
    }
};
