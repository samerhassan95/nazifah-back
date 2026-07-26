<?php

namespace Modules\Discount\Database\Seeders;

use Illuminate\Database\Seeder;

class DiscountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $discounts = [
            ['code' => 'WELCOME10', 'name' => ['ar' => 'خصم ترحيبي', 'en' => 'Welcome Discount'], 'description' => ['ar' => 'خصم 10% للعملاء الجدد', 'en' => '10% off for new customers'], 'type' => 'percentage', 'value' => 10.00, 'min_order_amount' => 50.00, 'max_discount_amount' => 20.00, 'is_active' => true, 'start_date' => now(), 'end_date' => now()->addMonths(3)],
            ['code' => 'SUMMER20', 'name' => ['ar' => 'خصم الصيف', 'en' => 'Summer Discount'], 'description' => ['ar' => 'خصم 20% لفصل الصيف', 'en' => '20% off summer sale'], 'type' => 'percentage', 'value' => 20.00, 'min_order_amount' => 100.00, 'max_discount_amount' => 50.00, 'is_active' => true, 'start_date' => now(), 'end_date' => now()->addMonths(2), 'usage_limit' => 100],
            ['code' => 'FIXED50', 'name' => ['ar' => 'خصم ثابت', 'en' => 'Fixed Discount'], 'description' => ['ar' => 'خصم 50 ريال', 'en' => '50 SAR discount'], 'type' => 'fixed', 'value' => 50.00, 'min_order_amount' => 200.00, 'is_active' => true, 'start_date' => now(), 'end_date' => now()->addMonth(), 'usage_limit' => 50],
        ];

        foreach ($discounts as $discount) {
            \Modules\Discount\Models\Discount::create($discount);
        }
    }
}
