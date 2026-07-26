<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONSOLIDATED MIGRATION FOR SERVICES TABLE
 *
 * This migration consolidates all service table changes into one file.
 * Original migrations consolidated:
 * - 2024_01_03_000000_create_services_table.php
 * - 2025_12_22_000001_add_is_active_to_services_table.php
 * - 2025_12_24_000011_add_is_available_to_services_table.php (later dropped)
 * - 2026_01_06_170000_add_image_to_services_table.php
 * - 2026_01_06_173214_replace_category_fields_with_category_id_in_services_table.php
 * - 2026_01_12_000001_add_vendor_id_to_services_table.php (later dropped)
 * - 2026_01_13_000004_make_category_id_required_in_services_table.php
 * - 2026_01_22_233717_make_vendor_id_required_in_services_table.php (later dropped)
 * - 2026_01_25_233705_add_icon_to_services_table.php
 * - 2026_02_03_000001_add_parent_id_to_services_table.php (later dropped)
 * - 2026_02_04_000001_make_vendor_id_nullable_in_services_table.php (later dropped)
 * - 2026_02_09_232203_drop_is_available_from_services_table.php
 * - 2026_02_11_130400_drop_parent_id_from_services_table.php (vendor_id also dropped)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->json('service_name');
            $table->foreignId('category_id')->constrained('categories')->onDelete('restrict');
            $table->foreignId('icon_id')->nullable()->constrained('icons')->onDelete('set null');
            $table->integer('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
