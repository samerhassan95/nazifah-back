<?php

namespace Modules\BannerOffer\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\BannerOffer\Models\BannerOffer;

class BannerOfferSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $banners = [
            [
                'title' => ['ar' => 'خصم 50% على جميع الخدمات', 'en' => '50% Off All Services'],
                'description' => ['ar' => 'احصل على خصم 50% على جميع خدمات الغسيل والكي', 'en' => 'Get 50% discount on all washing and ironing services'],
                'image' => 'banners/summer_sale.jpg',
                'link' => '/offers/summer-sale',
                'is_active' => true,
                'start_date' => now(),
                'end_date' => now()->addMonths(2),
                'order' => 1,
            ],
            [
                'title' => ['ar' => 'خدمة التوصيل مجاناً', 'en' => 'Free Delivery Service'],
                'description' => ['ar' => 'توصيل مجاني للطلبات فوق 100 ريال', 'en' => 'Free delivery for orders above 100 SAR'],
                'image' => 'banners/free_delivery.jpg',
                'link' => '/services/delivery',
                'is_active' => true,
                'start_date' => now(),
                'end_date' => now()->addMonth(),
                'order' => 2,
            ],
            [
                'title' => ['ar' => 'خدمة الغسيل السريع', 'en' => 'Express Wash Service'],
                'description' => ['ar' => 'خدمة غسيل سريعة خلال 24 ساعة', 'en' => 'Fast wash service within 24 hours'],
                'image' => 'banners/express_wash.jpg',
                'link' => '/services/express',
                'is_active' => true,
                'start_date' => now(),
                'end_date' => now()->addMonths(6),
                'order' => 3,
            ],
            [
                'title' => ['ar' => 'تطبيق جوال جديد', 'en' => 'New Mobile App'],
                'description' => ['ar' => 'حمل تطبيقنا الجديد واحصل على خصم 15%', 'en' => 'Download our new app and get 15% discount'],
                'image' => 'banners/mobile_app.jpg',
                'link' => '/app-download',
                'is_active' => true,
                'start_date' => now(),
                'end_date' => now()->addMonths(12),
                'order' => 4,
            ],
            [
                'title' => ['ar' => 'برنامج العضوية المميزة', 'en' => 'Premium Membership Program'],
                'description' => ['ar' => 'انضم لبرنامج العضوية واحصل على مزايا حصرية', 'en' => 'Join our membership program for exclusive benefits'],
                'image' => 'banners/membership.jpg',
                'link' => '/membership',
                'is_active' => true,
                'start_date' => now(),
                'end_date' => now()->addMonths(3),
                'order' => 5,
            ],
        ];

        foreach ($banners as $banner) {
            BannerOffer::create($banner);
        }
    }
}
