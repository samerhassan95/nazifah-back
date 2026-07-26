<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONSOLIDATED MIGRATION FOR SERVICE_ADDITIONS TABLE
 *
 * Original migrations consolidated:
 * - 2024_01_04_000000_create_service_additions_table.php
 * - 2026_01_19_000001_add_vendor_id_to_service_additions_table.php
 * - 2026_01_22_233755_make_vendor_id_required_in_service_additions_table.php
 * - 2026_01_26_000001_add_image_to_service_additions_table.php
 * - 2026_03_02_100001_drop_image_from_pieces_and_service_additions.php (from database/migrations)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_additions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->onDelete('cascade');
            $table->json('name'); // Multi-language name
            $table->decimal('price', 10, 2);
            $table->foreignId('icon_id')->nullable()->constrained('icons')->onDelete('set null');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_additions');
    }
};
