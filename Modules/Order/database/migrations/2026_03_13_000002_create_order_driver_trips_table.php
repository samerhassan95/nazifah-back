<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_driver_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->foreignId('driver_id')->constrained('drivers')->onDelete('cascade');
            $table->string('trip_type', 20); // 'pickup' | 'delivery'
            $table->string('status', 20)->default('pending'); // 'pending' | 'completed'
            $table->timestamp('completed_at')->nullable();
            $table->decimal('delivery_fee', 10, 2)->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'driver_id', 'trip_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_driver_trips');
    }
};
