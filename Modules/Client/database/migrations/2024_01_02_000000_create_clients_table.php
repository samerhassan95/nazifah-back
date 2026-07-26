<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->unique(); // Primary identifier
            $table->string('full_name', 50)->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('image')->nullable(); // Profile image
            $table->string('street_name')->nullable();
            $table->string('building_number')->nullable();
            $table->string('street_number')->nullable();
            $table->decimal('latitude', 10, 8)->nullable(); // GPS coordinates
            $table->decimal('longitude', 11, 8)->nullable();
            $table->json('location')->nullable(); // Translatable address
            $table->string('otp_code')->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
