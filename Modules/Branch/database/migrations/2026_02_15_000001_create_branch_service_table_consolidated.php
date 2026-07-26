<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONSOLIDATED MIGRATION FOR BRANCH_SERVICE PIVOT TABLE
 *
 * Original migrations consolidated:
 * - 2026_01_12_000001_create_branch_service_table.php
 * - 2026_02_16_104247_add_image_and_description_to_branch_service_table.php
 * - 2026_03_02_203348_drop_image_add_icon_id_to_branch_service_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('service_id')->constrained('services')->onDelete('cascade');
            $table->json('description')->nullable();
            $table->foreignId('icon_id')->nullable()->constrained('icons')->onDelete('set null');
            $table->timestamps();

            $table->unique(['branch_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_service');
    }
};
