<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONSOLIDATED MIGRATION FOR ORDERS TABLE
 *
 * This migration consolidates all order table changes into one file.
 * Original migrations consolidated:
 * - 2024_01_08_000001_create_orders_table.php
 * - 2024_01_08_000005_add_driver_id_to_orders_table.php
 * - 2025_01_01_000001_add_tax_amount_to_orders_table.php (from database/migrations)
 * - 2025_01_01_000002_add_delivery_fee_to_orders_table.php (from database/migrations)
 * - 2025_12_25_000004_add_pickup_delivery_flags_to_orders_table.php
 * - 2025_12_28_093505_change_delivery_method_to_booleans_in_orders_table.php (from database/migrations)
 * - 2025_12_28_093850_add_payment_method_to_orders_table.php (from database/migrations)
 * - 2026_01_12_000001_add_branch_id_to_orders_table.php
 * - 2026_01_12_000002_remove_vendor_id_and_require_branch_id_in_orders_table.php
 * - 2026_02_04_120206_add_attachments_to_orders_table.php
 * - 2026_02_05_174838_add_vendor_review_fields_to_orders_table.php (from database/migrations)
 * - 2026_02_27_000001_change_status_to_string_in_orders_table.php (from database/migrations)
 * - 2026_03_05_151047_add_distance_to_orders_table.php (from database/migrations)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->onDelete('set null');
            $table->string('order_number')->unique();
            $table->string('status', 50)->default('pending'); // VARCHAR instead of ENUM
            $table->boolean('vendor_reviewed')->default(false);
            $table->timestamp('vendor_reviewed_at')->nullable();
            $table->text('vendor_review_notes')->nullable();
            $table->boolean('client_approved')->default(false);
            $table->timestamp('client_approved_at')->nullable();
            $table->decimal('original_total_amount', 10, 2)->nullable();
            $table->decimal('original_final_amount', 10, 2)->nullable();
            $table->decimal('total_amount', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->decimal('final_amount', 10, 2);
            $table->foreignId('pickup_address_id')->nullable()->constrained('addresses')->onDelete('set null');
            $table->boolean('pickup_at_vendor')->default(false);
            $table->boolean('delivery_at_vendor')->default(false);
            $table->foreignId('delivery_address_id')->nullable()->constrained('addresses')->onDelete('set null');
            $table->timestamp('pickup_time')->nullable();
            $table->timestamp('estimated_delivery_time')->nullable();
            $table->timestamp('actual_delivery_time')->nullable();
            $table->decimal('distance', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->json('attachments')->nullable();
            $table->string('qr_code')->unique();
            $table->string('payment_method')->nullable();
            $table->integer('rating')->nullable();
            $table->text('review')->nullable();
            $table->text('cancelled_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
