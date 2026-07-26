<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONSOLIDATED MIGRATION FOR BRANCH_PIECE PIVOT TABLE
 *
 * Original migrations consolidated:
 * - 2026_01_12_000002_create_branch_piece_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_piece', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('piece_id')->constrained('pieces')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['branch_id', 'piece_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_piece');
    }
};
