<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIGRATION FOR VENDOR_EMPLOYEE_AUTH_SESSIONS TABLE
 * No consolidation needed - single migration file
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_employee_auth_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_employee_id')->nullable()->constrained('vendor_employees')->onDelete('cascade');
            $table->string('phone', 20)->index();
            $table->string('session_key', 64)->unique();
            $table->string('otp_code', 10)->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->unsignedTinyInteger('otp_attempts')->default(0);
            $table->timestamp('otp_attempts_reset_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_employee_auth_sessions');
    }
};
