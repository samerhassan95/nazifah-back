<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branch-specific offering for a vendor service addition (name + price per branch).
 * Catalog row in service_additions is not modified when assigning to a branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_service_addition', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('service_addition_id')->constrained('service_additions')->onDelete('cascade');
            $table->json('name')->nullable();
            $table->decimal('price', 10, 2);
            $table->foreignId('icon_id')->nullable()->constrained('icons')->onDelete('set null');
            $table->timestamps();

            $table->unique(['branch_id', 'service_addition_id'], 'branch_service_addition_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_service_addition');
    }
};
