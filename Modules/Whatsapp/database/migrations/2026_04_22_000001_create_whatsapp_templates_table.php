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
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('content');
            $table->json('variables')->nullable(); // ['name','order_id']
            $table->string('category')->default('marketing');
            // enum: marketing, utility, authentication
            $table->string('language')->default('ar');
            $table->string('status')->default('pending');
            // enum: pending, approved, rejected
            $table->string('whatsapp_template_id')->nullable();
            $table->string('header_type')->nullable();
            // enum: none, text, image, video
            $table->string('header_content')->nullable();
            $table->string('footer')->nullable();
            $table->json('buttons')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
