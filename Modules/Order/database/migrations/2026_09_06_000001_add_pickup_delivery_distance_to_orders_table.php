<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `distance` only ever stored the combined pickup+delivery total, which is
 * meaningless once pickup and delivery are two different addresses (e.g.
 * drop off at home, deliver to the office). These split legs let the app
 * show each distance on its own for an already-created order too, matching
 * what calculate() has returned since the pickup_distance_km/
 * delivery_distance_km split was added there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('pickup_distance', 10, 2)->nullable()->after('distance');
            $table->decimal('delivery_distance', 10, 2)->nullable()->after('pickup_distance');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['pickup_distance', 'delivery_distance']);
        });
    }
};
