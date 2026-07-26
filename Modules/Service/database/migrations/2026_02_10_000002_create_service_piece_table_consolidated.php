<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONSOLIDATED MIGRATION FOR SERVICE_PIECE PIVOT TABLE
 *
 * Original migrations consolidated:
 * - 2025_12_25_000002_create_service_piece_table.php
 * - 2026_01_22_233804_remove_default_from_service_piece_price.php
 * - 2026_03_02_004102_add_custom_fields_to_service_piece_table.php (from database/migrations)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_piece', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->onDelete('cascade');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->onDelete('cascade');
            $table->foreignId('piece_id')->constrained('pieces')->onDelete('cascade');
            $table->decimal('price', 10, 2); // No default value
            $table->timestamps();

            $table->unique(['service_id', 'piece_id'], 'service_piece_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_piece');
    }
};
