<?php

namespace Modules\Ad\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Ad\Models\Ad;

class AdSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $ads = [
            [
                'title' => ['ar' => 'مغسلة الأناقة الجديدة', 'en' => 'New Elegance Laundry'],
                'description' => ['ar' => 'أفضل خدمات الغسيل والكي في المدينة', 'en' => 'Best washing and ironing services in the city'],
                'image' => 'ads/elegance_laundry.jpg',
                'link' => '/vendors/elegance-laundry',
                'type' => 'vendor',
                'is_active' => true,
                'order' => 1,
                'start_date' => now(),
                'end_date' => now()->addDays(30),
            ],
            [
                'title' => ['ar' => 'خدمة التنظيف الجاف المتميزة', 'en' => 'Premium Dry Cleaning Service'],
                'description' => ['ar' => 'تنظيف جاف عالي الجودة لجميع أنواع الملابس', 'en' => 'High-quality dry cleaning for all clothing types'],
                'image' => 'ads/dry_cleaning.jpg',
                'link' => '/services/dry-cleaning',
                'type' => 'service',
                'is_active' => true,
                'order' => 2,
                'start_date' => now(),
                'end_date' => now()->addDays(45),
            ],
            [
                'title' => ['ar' => 'تطبيق جديد للطلبات السريعة', 'en' => 'New App for Quick Orders'],
                'description' => ['ar' => 'اطلب خدمات الغسيل بضغطة زر واحدة', 'en' => 'Order laundry services with one click'],
                'image' => 'ads/mobile_app_ad.jpg',
                'link' => '/app-download',
                'type' => 'app',
                'is_active' => true,
                'order' => 3,
                'start_date' => now(),
                'end_date' => now()->addMonths(2),
            ],
            [
                'title' => ['ar' => 'عروض رمضان المميزة', 'en' => 'Special Ramadan Offers'],
                'description' => ['ar' => 'خصومات حصرية خلال شهر رمضان المبارك', 'en' => 'Exclusive discounts during the holy month of Ramadan'],
                'image' => 'ads/ramadan_offers.jpg',
                'link' => '/offers/ramadan',
                'type' => 'seasonal',
                'is_active' => true,
                'order' => 4,
                'start_date' => now(),
                'end_date' => now()->addDays(60),
            ],
            [
                'title' => ['ar' => 'شريك توصيل موثوق', 'en' => 'Trusted Delivery Partner'],
                'description' => ['ar' => 'توصيل سريع وآمن لجميع أنحاء المدينة', 'en' => 'Fast and safe delivery to all parts of the city'],
                'image' => 'ads/delivery_partner.jpg',
                'link' => '/delivery-info',
                'type' => 'delivery',
                'is_active' => true,
                'order' => 5,
                'start_date' => now(),
                'end_date' => now()->addDays(90),
            ],
        ];

        foreach ($ads as $ad) {
            Ad::create($ad);
        }
    }
}
