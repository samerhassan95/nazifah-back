<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('earner_type'); // vendor, driver, platform
            $table->unsignedBigInteger('earner_id')->nullable(); // vendor_id or driver_id (null for platform)
            $table->decimal('amount', 10, 2);
            $table->string('type'); // commission, delivery_fee, order_revenue
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['earner_type', 'earner_id']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('earnings');
    }
};
