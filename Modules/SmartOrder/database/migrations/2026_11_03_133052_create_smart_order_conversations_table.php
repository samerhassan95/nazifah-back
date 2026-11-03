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
        Schema::create('smart_order_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('laundry_selection', 20)->nullable()->comment('user|auto_single|picked');
            $table->string('status', 30)->default('needs_clarification')->comment('needs_clarification|ready|not_understood|confirmed');
            $table->json('messages')->nullable()->comment('Raw conversation history fed back to Claude each turn');
            $table->json('draft_lines')->nullable();
            $table->decimal('estimated_total', 10, 2)->nullable();
            $table->json('pending_question')->nullable();
            $table->text('last_reply')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'last_activity_at']);
            $table->index('confirmed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('smart_order_conversations');
    }
};
