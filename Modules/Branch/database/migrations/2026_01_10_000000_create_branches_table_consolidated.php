<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONSOLIDATED MIGRATION FOR BRANCHES TABLE
 *
 * This migration consolidates all branch table changes into one file.
 * Original migrations consolidated:
 * - 2024_01_04_000000_create_branches_table.php
 * - 2025_01_05_000000_add_vendor_id_to_branches_table.php
 * - 2025_12_24_000000_add_is_active_to_branches_table.php
 * - 2026_01_11_100001_add_zone_and_coordinates_to_branches_table.php
 * - 2026_01_23_003719_add_rating_and_rate_count_to_branches_table.php (from database/migrations)
 * - 2026_03_01_122241_add_logo_working_hours_national_address_to_branches_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained('zones')->onDelete('set null');
            $table->json('name');
            $table->string('phone_number');
            $table->string('land_phone')->nullable();
            $table->json('location');
            $table->string('national_address')->nullable();
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->string('store_front')->nullable();
            $table->string('logo')->nullable();
            $table->json('description')->nullable();
            $table->json('working_hours')->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('rating', 3, 2)->default(0);
            $table->unsignedInteger('rate_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
