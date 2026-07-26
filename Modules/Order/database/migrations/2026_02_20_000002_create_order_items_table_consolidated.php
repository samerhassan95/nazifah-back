<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONSOLIDATED MIGRATION FOR ORDER_ITEMS TABLE
 *
 * Original migrations consolidated:
 * - 2024_01_08_000002_create_order_items_table.php
 * - 2026_02_05_175020_add_vendor_review_fields_to_order_items_table.php (from database/migrations)
 * - 2026_02_10_000001_add_images_to_order_items_table.php
 * - 2026_02_12_161924_add_price_breakdown_to_order_items_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->foreignId('piece_id')->constrained('pieces')->onDelete('restrict');
            $table->foreignId('service_id')->constrained('services')->onDelete('restrict');
            $table->decimal('piece_price', 10, 2)->default(0);
            $table->decimal('service_price', 10, 2)->default(0);
            $table->integer('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total_price', 10, 2);

            // Vendor review status for each item
            $table->enum('vendor_status', ['pending', 'accepted', 'rejected', 'modified'])->default('pending');
            $table->text('vendor_notes')->nullable();

            // Original values before vendor modification
            $table->integer('original_quantity')->nullable();
            $table->decimal('original_unit_price', 10, 2)->nullable();
            $table->decimal('original_total_price', 10, 2)->nullable();

            // Modified values suggested by vendor
            $table->integer('modified_quantity')->nullable();
            $table->decimal('modified_unit_price', 10, 2)->nullable();
            $table->decimal('modified_total_price', 10, 2)->nullable();

            // Client approval for modifications
            $table->boolean('client_approved_modification')->default(false);

            $table->text('notes')->nullable();
            $table->string('images')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
