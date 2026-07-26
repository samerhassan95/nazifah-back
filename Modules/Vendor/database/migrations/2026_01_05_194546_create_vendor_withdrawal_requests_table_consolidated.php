<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONSOLIDATED MIGRATION FOR VENDOR_WITHDRAWAL_REQUESTS TABLE
 *
 * Original migrations consolidated:
 * - 2026_01_05_194546_create_vendor_withdrawal_requests_table.php
 * - 2026_01_05_194859_add_bank_account_foreign_key_to_vendor_withdrawal_requests.php
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->onDelete('cascade');
            $table->foreignId('bank_account_id')->nullable()->constrained('vendor_bank_accounts')->onDelete('set null');
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['pending', 'approved', 'rejected', 'completed'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('vendor_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_withdrawal_requests');
    }
};
