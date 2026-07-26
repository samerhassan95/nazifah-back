<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->json('name'); // Translatable field
            $table->json('description')->nullable(); // Translatable field
            $table->decimal('center_latitude', 10, 8)->nullable(); // For circular zones
            $table->decimal('center_longitude', 11, 8)->nullable(); // For circular zones
            $table->decimal('radius', 8, 2)->nullable(); // Radius in kilometers for circular zones
            $table->boolean('is_active')->default(true);
            $table->decimal('delivery_fee', 8, 2)->default(0); // Zone-specific delivery fee
            $table->decimal('minimum_order', 8, 2)->default(0); // Minimum order for this zone
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zones');
    }
};
