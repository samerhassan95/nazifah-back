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
            try {
                $table->dropForeign(['zone_id']);
            } catch (\Throwable $e) {
                // Foreign key may not exist in some environments.
            }
        });

        Schema::table('addresses', function (Blueprint $table) {
            $table->unsignedBigInteger('zone_id')->nullable()->change();
            $table->foreign('zone_id')->references('id')->on('zones')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            try {
                $table->dropForeign(['zone_id']);
            } catch (\Throwable $e) {
                // No-op
            }
        });

        Schema::table('addresses', function (Blueprint $table) {
            $table->unsignedBigInteger('zone_id')->nullable(false)->change();
            $table->foreign('zone_id')->references('id')->on('zones')->cascadeOnDelete();
        });
    }
};
