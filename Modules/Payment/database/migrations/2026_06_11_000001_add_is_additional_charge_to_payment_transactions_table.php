<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promote the additional-charge flag from response_data JSON to a real
     * column so payment_status can be scoped to exclude surcharge transactions,
     * and record how a surcharge leg relates to the original authorization.
     */
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            // Source of truth for "is this a surcharge / split leg, not the
            // primary checkout payment". Existing rows default to false.
            $table->boolean('is_additional_charge')->default(false)->after('payment_method');

            // 'supplemental' = same method as the original authorization,
            // 'alternative' = a different method (e.g. paying the delta with
            // a second card or via wallet). Null for the primary payment.
            $table->string('authorization_type', 20)->nullable()->after('is_additional_charge');

            $table->index(['order_id', 'is_additional_charge']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'is_additional_charge']);
            $table->dropColumn(['is_additional_charge', 'authorization_type']);
        });
    }
};
