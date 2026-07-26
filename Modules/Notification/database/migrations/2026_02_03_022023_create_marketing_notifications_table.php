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
        Schema::create('marketing_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('notification_title');
            $table->text('description');
            $table->string('user_target_type'); // client, driver, vendor, all
            $table->json('target_user_ids')->nullable(); // array of user IDs or ['all']
            $table->date('sending_date');
            $table->time('sending_time');
            $table->boolean('is_sent')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->onDelete('set null');
            $table->timestamps();

            $table->index(['sending_date', 'sending_time', 'is_sent']);
            $table->index('user_target_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketing_notifications');
    }
};
