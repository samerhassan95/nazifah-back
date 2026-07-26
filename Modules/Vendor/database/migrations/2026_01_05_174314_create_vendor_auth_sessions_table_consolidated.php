<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONSOLIDATED MIGRATION FOR VENDOR_AUTH_SESSIONS TABLE
 *
 * Original migrations consolidated:
 * - 2026_01_05_174314_create_vendor_auth_sessions_table.php
 * - 2026_01_05_180000_add_registration_data_to_vendor_auth_sessions_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_auth_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_key', 64)->unique();
            $table->string('phone', 15)->index();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->onDelete('cascade');
            $table->string('otp_code', 6)->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->unsignedTinyInteger('otp_attempts')->default(0);
            $table->timestamp('rate_limit_reset_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('official_number')->nullable();
            $table->timestamps();

            $table->index(['session_key', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_auth_sessions');
    }
};
