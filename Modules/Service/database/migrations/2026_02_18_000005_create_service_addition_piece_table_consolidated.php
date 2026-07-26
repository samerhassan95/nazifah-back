<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONSOLIDATED MIGRATION FOR SERVICE_ADDITION_PIECE PIVOT TABLE
 *
 * Original migrations consolidated:
 * - 2026_01_13_000005_create_service_addition_piece_table.php
 * - 2026_01_13_000006_add_branch_id_and_price_to_service_addition_piece_table.php
 * - 2026_01_22_233823_make_branch_id_nullable_in_service_addition_piece.php
 * - 2026_01_22_233836_remove_default_from_service_addition_piece_price.php
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_addition_piece', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_addition_id')->constrained('service_additions')->onDelete('cascade');
            $table->foreignId('piece_id')->constrained('pieces')->onDelete('cascade');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->onDelete('cascade');
            $table->decimal('price', 10, 2); // No default value
            $table->timestamps();

            $table->unique(['service_addition_id', 'piece_id', 'branch_id'], 'service_addition_piece_branch_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_addition_piece');
    }
};
