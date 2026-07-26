<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONSOLIDATED MIGRATION FOR BRANCH_DRIVER PIVOT TABLE
 *
 * Original migrations consolidated:
 * - 2026_01_12_000003_create_branch_driver_table.php
 * - 2026_03_02_212208_drop_is_active_from_branch_driver_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_driver', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('driver_id')->constrained('drivers')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['branch_id', 'driver_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_driver');
    }
};
