<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->unique(); // Primary identifier
            $table->json('full_name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('image')->nullable();
            $table->string('license_number')->nullable();
            $table->string('vehicle_type')->nullable();
            $table->string('vehicle_number')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->decimal('latitude', 12, 8)->nullable(); // Current GPS coordinates
            $table->decimal('longitude', 11, 8)->nullable();
            $table->json('location')->nullable(); // Translatable address
            $table->boolean('is_available')->default(false); // Available for orders
            $table->decimal('rating', 3, 2)->default(0); // Driver rating
            $table->integer('total_orders')->default(0);
            $table->string('password')->nullable();
            $table->string('otp_code')->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->integer('otp_attempts')->default(0);
            $table->timestamp('rate_limit_reset_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['phone', 'is_verified']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
