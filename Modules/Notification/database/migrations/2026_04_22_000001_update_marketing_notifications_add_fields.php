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
        Schema::table('marketing_notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('marketing_notifications', 'status')) {
                $table->string('status')->default('draft');
            }
            if (! Schema::hasColumn('marketing_notifications', 'scheduled_at')) {
                $table->timestamp('scheduled_at')->nullable();
            }
            if (! Schema::hasColumn('marketing_notifications', 'deep_link')) {
                $table->string('deep_link')->nullable();
            }
            if (! Schema::hasColumn('marketing_notifications', 'image_url')) {
                $table->string('image_url')->nullable();
            }
            if (! Schema::hasColumn('marketing_notifications', 'segment_filters')) {
                $table->json('segment_filters')->nullable();
            }
            if (! Schema::hasColumn('marketing_notifications', 'total_recipients')) {
                $table->integer('total_recipients')->default(0);
            }
            if (! Schema::hasColumn('marketing_notifications', 'sent_count')) {
                $table->integer('sent_count')->default(0);
            }
            if (! Schema::hasColumn('marketing_notifications', 'read_count')) {
                $table->integer('read_count')->default(0);
            }
            if (! Schema::hasColumn('marketing_notifications', 'failed_count')) {
                $table->integer('failed_count')->default(0);
            }
            if (! Schema::hasColumn('marketing_notifications', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketing_notifications', function (Blueprint $table) {
            $columns = [
                'status', 'scheduled_at', 'deep_link', 'image_url',
                'segment_filters', 'total_recipients', 'sent_count',
                'read_count', 'failed_count',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('marketing_notifications', $column)) {
                    $table->dropColumn($column);
                }
            }

            if (Schema::hasColumn('marketing_notifications', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
