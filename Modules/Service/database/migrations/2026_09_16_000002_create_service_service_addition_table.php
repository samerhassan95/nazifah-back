<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_service_addition', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->onDelete('cascade');
            $table->foreignId('service_addition_id')->constrained('service_additions')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['service_id', 'service_addition_id'], 'service_service_addition_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_service_addition');
    }
};
