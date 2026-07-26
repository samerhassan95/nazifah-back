<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->json('name'); // Translatable field
            $table->json('tagline')->nullable(); // Translatable field
            $table->decimal('price_month', 10, 2)->default(0);
            $table->decimal('price_year', 10, 2)->default(0);
            $table->string('currency', 10)->default('SAR');
            $table->boolean('is_featured')->default(false);
            $table->boolean('has_discount')->default(false);
            $table->integer('discount_percentage')->default(0);
            $table->integer('branch_count')->default(1)->comment('Number of branches allowed');
            $table->integer('order_count')->nullable()->comment('Max orders allowed, null = unlimited');
            $table->boolean('has_discount_codes')->default(false);
            $table->boolean('has_special_delivery')->default(false);
            $table->boolean('has_reports')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
