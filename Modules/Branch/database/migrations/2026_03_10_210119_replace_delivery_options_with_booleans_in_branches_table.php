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
        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('home_pickup')->default(false)->after('rate_count');
            $table->boolean('self_dropoff')->default(false)->after('home_pickup');
            $table->boolean('home_delivery')->default(false)->after('self_dropoff');
            $table->boolean('self_pickup')->default(false)->after('home_delivery');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn([
                'home_pickup',
                'self_dropoff',
                'home_delivery',
                'self_pickup',
            ]);
        });
    }
};
