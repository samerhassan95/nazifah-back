<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zone_related_zone', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zone_id')->constrained('zones')->cascadeOnDelete();
            $table->foreignId('related_zone_id')->constrained('zones')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['zone_id', 'related_zone_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zone_related_zone');
    }
};
