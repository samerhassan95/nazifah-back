<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Zone\Models\Zone;

class SaudiCitiesZoneSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $saudiCities = [
            [
                'code' => 'SA01',
                'name' => ['en' => 'Ar Riyad', 'ar' => 'الرياض'],
                'description' => ['en' => 'Riyadh Region', 'ar' => 'منطقة الرياض'],
                'center_latitude' => 24.7136,
                'center_longitude' => 46.6753,
            ],
            [
                'code' => 'SA02',
                'name' => ['en' => 'Makkah', 'ar' => 'جدة'],
                'description' => ['en' => 'Makkah Region', 'ar' => 'منطقة مكة المكرمة'],
                'center_latitude' => 21.4858,
                'center_longitude' => 39.1925,
            ],
            [
                'code' => 'SA03',
                'name' => ['en' => 'Al Madinah', 'ar' => 'المدينة المنورة'],
                'description' => ['en' => 'Al Madinah Region', 'ar' => 'منطقة المدينة المنورة'],
                'center_latitude' => 24.5247,
                'center_longitude' => 39.5692,
            ],
            [
                'code' => 'SA04',
                'name' => ['en' => 'Ash Sharqiyah', 'ar' => 'المنطقة الشرقية'],
                'description' => ['en' => 'Eastern Region', 'ar' => 'المنطقة الشرقية'],
                'center_latitude' => 26.4207,
                'center_longitude' => 50.0888,
            ],
            [
                'code' => 'SA05',
                'name' => ['en' => 'Al Quassim', 'ar' => 'القصيم'],
                'description' => ['en' => 'Al Qassim Region', 'ar' => 'منطقة القصيم'],
                'center_latitude' => 26.3260,
                'center_longitude' => 43.9750,
            ],
            [
                'code' => 'SA06',
                'name' => ['en' => "Ha'il", 'ar' => 'حائل'],
                'description' => ['en' => "Ha'il Region", 'ar' => 'منطقة حائل'],
                'center_latitude' => 27.5114,
                'center_longitude' => 41.7208,
            ],
            [
                'code' => 'SA07',
                'name' => ['en' => 'Tabuk', 'ar' => 'تبوك'],
                'description' => ['en' => 'Tabuk Region', 'ar' => 'منطقة تبوك'],
                'center_latitude' => 28.3838,
                'center_longitude' => 36.5550,
            ],
            [
                'code' => 'SA08',
                'name' => ['en' => 'Al Hudud ash Shamaliyah', 'ar' => 'الحدود الشمالية'],
                'description' => ['en' => 'Northern Borders Region', 'ar' => 'منطقة الحدود الشمالية'],
                'center_latitude' => 30.9843,
                'center_longitude' => 41.1183,
            ],
            [
                'code' => 'SA09',
                'name' => ['en' => 'Jizan', 'ar' => 'جيزان'],
                'description' => ['en' => 'Jizan Region', 'ar' => 'منطقة جازان'],
                'center_latitude' => 16.8892,
                'center_longitude' => 42.5511,
            ],
            [
                'code' => 'SA10',
                'name' => ['en' => 'Najran', 'ar' => 'نجران'],
                'description' => ['en' => 'Najran Region', 'ar' => 'منطقة نجران'],
                'center_latitude' => 17.4933,
                'center_longitude' => 44.1277,
            ],
            [
                'code' => 'SA11',
                'name' => ['en' => 'Al Bahah', 'ar' => 'الباحة'],
                'description' => ['en' => 'Al Bahah Region', 'ar' => 'منطقة الباحة'],
                'center_latitude' => 20.0129,
                'center_longitude' => 41.4677,
            ],
            [
                'code' => 'SA12',
                'name' => ['en' => 'Al Jawf', 'ar' => 'الجوف'],
                'description' => ['en' => 'Al Jawf Region', 'ar' => 'منطقة الجوف'],
                'center_latitude' => 29.8867,
                'center_longitude' => 39.3206,
            ],
            [
                'code' => 'SA14',
                'name' => ['en' => '`Asir', 'ar' => 'عسير'],
                'description' => ['en' => 'Asir Region', 'ar' => 'منطقة عسير'],
                'center_latitude' => 18.2164,
                'center_longitude' => 42.5053,
            ],
        ];

        foreach ($saudiCities as $city) {
            Zone::updateOrCreate(
                ['code' => $city['code']],
                [
                    'name' => $city['name'],
                    'description' => $city['description'],
                    'points' => [
                        ['latitude' => $city['center_latitude'] + 0.05, 'longitude' => $city['center_longitude'] - 0.05],
                        ['latitude' => $city['center_latitude'] + 0.05, 'longitude' => $city['center_longitude'] + 0.05],
                        ['latitude' => $city['center_latitude'] - 0.05, 'longitude' => $city['center_longitude'] + 0.05],
                        ['latitude' => $city['center_latitude'] - 0.05, 'longitude' => $city['center_longitude'] - 0.05],
                    ],
                    'center_latitude' => $city['center_latitude'],
                    'center_longitude' => $city['center_longitude'],
                    'radius' => 50, // 50km default radius
                    'is_active' => true,
                    'delivery_fee' => 15.00,
                    'minimum_order' => 50.00,
                ]
            );
        }

        $this->command->info('Saudi cities zones seeded successfully!');
    }
}
