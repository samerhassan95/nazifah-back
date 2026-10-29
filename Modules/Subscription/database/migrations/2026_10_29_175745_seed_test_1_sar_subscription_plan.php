<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $exists = DB::table('subscription_plans')
            ->whereJsonContains('name->ar', 'باقة تجريبية')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('subscription_plans')->insert([
            'name' => json_encode(['ar' => 'باقة تجريبية', 'en' => 'Test Plan']),
            'tagline' => json_encode(['ar' => 'للاختبار فقط', 'en' => 'For testing only']),
            'price_month' => 1,
            'price_year' => 1,
            'currency' => 'SAR',
            'is_featured' => false,
            'has_discount' => false,
            'discount_percentage' => 0,
            'branch_count' => 1,
            'order_count' => 5,
            'has_discount_codes' => false,
            'has_special_delivery' => false,
            'has_reports' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('subscription_plans')
            ->whereJsonContains('name->ar', 'باقة تجريبية')
            ->delete();
    }
};
