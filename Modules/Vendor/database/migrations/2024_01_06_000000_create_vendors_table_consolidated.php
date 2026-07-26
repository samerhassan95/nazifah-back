<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONSOLIDATED MIGRATION FOR VENDORS TABLE
 *
 * This migration consolidates all vendor table changes into one file.
 * Original migrations consolidated:
 * - 2024_01_06_000000_create_vendors_table.php
 * - 2024_01_07_000002_add_auth_fields_to_vendors_table.php
 * - 2025_01_01_000001_add_delivery_price_per_km_to_vendors_table.php
 * - 2025_12_08_205545_add_missing_columns_to_vendors_table.php
 * - 2026_01_05_194217_add_wallet_balance_to_vendors_table.php
 * - 2026_01_06_162357_make_vendor_fields_nullable.php
 * - 2026_01_11_000001_add_is_banned_to_vendors_table.php
 * - 2026_01_11_100003_remove_location_fields_from_vendors_table.php
 * - 2026_01_12_000001_drop_unused_columns_from_vendors_table.php
 * - 2026_01_14_113357_add_secondary_phone_to_vendors_table.php (from database/migrations)
 * - 2026_01_14_113955_remove_max_capacity_category_id_rating_from_vendors_table.php (from database/migrations)
 * - 2026_01_25_225259_add_attachments_to_vendors_table.php
 * - 2026_03_09_215147_remove_secondary_phone_from_vendors_table.php
 * - 2026_03_09_215614_standardize_vat_number_terminology.php
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->json('name'); // Renamed from restaurant_name
            $table->string('email')->unique();
            $table->string('phone')->unique();
            $table->string('vat_number')->nullable();
            $table->string('official_number')->nullable();
            $table->json('attachments')->nullable();
            $table->string('logo')->nullable();
            $table->string('otp_code')->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->rememberToken();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_banned')->default(false);
            $table->text('ban_reason')->nullable();
            $table->timestamp('banned_at')->nullable();
            $table->decimal('delivery_price_per_km', 8, 2)->nullable();
            $table->decimal('wallet_balance', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
