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
        Schema::create('whatsapp_group_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_group_id')
                ->constrained()->onDelete('cascade');
            $table->foreignId('user_id')
                ->constrained('users')->onDelete('cascade'); // Assumes users table or client table
            $table->timestamp('added_at')->useCurrent();
            $table->timestamps();

            $table->unique(['whatsapp_group_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_group_user');
    }
};
