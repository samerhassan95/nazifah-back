<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONSOLIDATED MIGRATION FOR ORDER_ITEM_ADDITIONAL_SERVICES TABLE
 *
 * Original migrations consolidated:
 * - 2026_02_06_211905_create_order_item_additional_services_table.php
 * - 2026_02_27_000002_add_vendor_review_to_order_item_additional_services.php
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_additional_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->onDelete('cascade');
            $table->foreignId('service_addition_id')->constrained('service_additions')->onDelete('cascade');
            $table->decimal('price', 10, 2)->default(0);
            $table->integer('quantity')->default(1);
            $table->string('vendor_status')->nullable(); // accepted, rejected
            $table->text('vendor_notes')->nullable();
            $table->timestamps();

            $table->unique(['order_item_id', 'service_addition_id'], 'order_item_service_addition_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_additional_services');
    }
};
