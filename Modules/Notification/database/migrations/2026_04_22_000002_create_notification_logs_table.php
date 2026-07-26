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
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_notification_id')
                ->constrained('marketing_notifications')
                ->onDelete('cascade');
            $table->unsignedBigInteger('user_id'); // Just use unsignedBigInteger as we don't know the exact user table relation (client/driver/vendor)
            $table->string('status')->default('sent'); // sent, delivered, read, failed
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->string('device_token')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['marketing_notification_id', 'user_id']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
