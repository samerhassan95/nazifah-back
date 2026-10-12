<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A service can legitimately belong to more than one category (e.g. "Regular
 * Washing" applies to both Clothing and Bedding & Blankets) — the Master
 * Catalog's 03_Section_Service sheet confirmed this is real data, not an
 * import artifact. services.category_id stays as the single "primary"
 * category (required elsewhere in the app), and this pivot additionally
 * records every category a service is associated with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->onDelete('cascade');
            $table->foreignId('service_id')->constrained('services')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['category_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_service');
    }
};
