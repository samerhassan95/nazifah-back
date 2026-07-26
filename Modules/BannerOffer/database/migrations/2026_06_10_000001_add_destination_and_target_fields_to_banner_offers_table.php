<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banner_offers', function (Blueprint $table) {
            $table->string('destination_type')->nullable()->after('link');
            $table->unsignedBigInteger('destination_id')->nullable()->after('destination_type');
            $table->string('user_target_type')->default('all')->after('destination_id');
            $table->json('target_user_ids')->nullable()->after('user_target_type');

            $table->index(['destination_type', 'destination_id']);
            $table->index('user_target_type');
        });
    }

    public function down(): void
    {
        Schema::table('banner_offers', function (Blueprint $table) {
            $table->dropIndex(['destination_type', 'destination_id']);
            $table->dropIndex(['user_target_type']);
            $table->dropColumn([
                'destination_type',
                'destination_id',
                'user_target_type',
                'target_user_ids',
            ]);
        });
    }
};
