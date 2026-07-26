<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONSOLIDATED MIGRATION FOR VENDOR_EMPLOYEES TABLE
 *
 * Original migrations consolidated:
 * - 2026_01_11_100001_create_vendor_employees_table.php
 * - 2026_01_15_145344_add_vendor_validation_to_vendor_employees_table.php (from database/migrations)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->onDelete('cascade');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->unique();
            $table->string('password');
            $table->string('image')->nullable();
            $table->enum('role', ['owner', 'manager', 'employee'])->default('employee');
            $table->string('otp_code', 10)->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_banned')->default(false);
            $table->string('ban_reason')->nullable();
            $table->timestamp('banned_at')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'is_active']);
            $table->index(['branch_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_employees');
    }
};
