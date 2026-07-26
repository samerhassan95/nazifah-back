<?php

namespace Modules\Zone\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Zone\Models\Zone;

class ZoneSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $zones = [
            [
                'name' => [
                    'ar' => 'منطقة الرياض الدائرية',
                    'en' => 'Riyadh Circle Zone',
                ],
                'description' => [
                    'ar' => 'منطقة دائرية تغطي وسط الرياض',
                    'en' => 'Circular zone covering central Riyadh',
                ],
                'points' => [
                    ['latitude' => 24.7160, 'longitude' => 46.6700],
                    ['latitude' => 24.7180, 'longitude' => 46.6800],
                    ['latitude' => 24.7120, 'longitude' => 46.6850],
                    ['latitude' => 24.7100, 'longitude' => 46.6750],
                ],
                'is_active' => true,
                'delivery_fee' => 5.00,
                'minimum_order' => 20.00,
            ],
            [
                'name' => [
                    'ar' => 'منطقة جدة الدائرية',
                    'en' => 'Jeddah Circle Zone',
                ],
                'description' => [
                    'ar' => 'منطقة دائرية تغطي وسط جدة',
                    'en' => 'Circular zone covering central Jeddah',
                ],
                'points' => [
                    ['latitude' => 21.3900, 'longitude' => 39.8550],
                    ['latitude' => 21.3950, 'longitude' => 39.8600],
                    ['latitude' => 21.3850, 'longitude' => 39.8650],
                    ['latitude' => 21.3800, 'longitude' => 39.8600],
                ],
                'is_active' => true,
                'delivery_fee' => 7.00,
                'minimum_order' => 25.00,
            ],
            [
                'name' => [
                    'ar' => 'منطقة الدمام الدائرية',
                    'en' => 'Dammam Circle Zone',
                ],
                'description' => [
                    'ar' => 'منطقة دائرية تغطي وسط الدمام',
                    'en' => 'Circular zone covering central Dammam',
                ],
                'points' => [
                    ['latitude' => 26.4210, 'longitude' => 50.0870],
                    ['latitude' => 26.4230, 'longitude' => 50.0900],
                    ['latitude' => 26.4180, 'longitude' => 50.0920],
                    ['latitude' => 26.4160, 'longitude' => 50.0880],
                ],
                'is_active' => true,
                'delivery_fee' => 6.00,
                'minimum_order' => 15.00,
            ],
            [
                'name' => [
                    'ar' => 'منطقة المدينة المنورة الدائرية',
                    'en' => 'Medina Circle Zone',
                ],
                'description' => [
                    'ar' => 'منطقة دائرية تغطي وسط المدينة المنورة',
                    'en' => 'Circular zone covering central Medina',
                ],
                'points' => [
                    ['latitude' => 24.4670, 'longitude' => 39.5980],
                    ['latitude' => 24.4700, 'longitude' => 39.6020],
                    ['latitude' => 24.4650, 'longitude' => 39.6050],
                    ['latitude' => 24.4620, 'longitude' => 39.6000],
                ],
                'is_active' => true,
                'delivery_fee' => 4.00,
                'minimum_order' => 30.00,
            ],
            [
                'name' => [
                    'ar' => 'منطقة أبها الدائرية',
                    'en' => 'Abha Circle Zone',
                ],
                'description' => [
                    'ar' => 'منطقة دائرية تغطي وسط أبها',
                    'en' => 'Circular zone covering central Abha',
                ],
                'points' => [
                    ['latitude' => 18.2150, 'longitude' => 42.5040],
                    ['latitude' => 18.2180, 'longitude' => 42.5070],
                    ['latitude' => 18.2130, 'longitude' => 42.5090],
                    ['latitude' => 18.2100, 'longitude' => 42.5060],
                ],
                'is_active' => true,
                'delivery_fee' => 3.00,
                'minimum_order' => 10.00,
            ],
            [
                'name' => [
                    'ar' => 'منطقة الرياض المضلعة 1',
                    'en' => 'Riyadh Polygon Zone 1',
                ],
                'description' => [
                    'ar' => 'منطقة مضلعة تغطي شمال الرياض',
                    'en' => 'Polygon zone covering North Riyadh',
                ],
                'points' => [
                    ['latitude' => 24.716, 'longitude' => 46.670],
                    ['latitude' => 24.718, 'longitude' => 46.680],
                    ['latitude' => 24.712, 'longitude' => 46.685],
                    ['latitude' => 24.710, 'longitude' => 46.675],
                ],
                'is_active' => true,
                'delivery_fee' => 8.00,
                'minimum_order' => 35.00,
            ],
            [
                'name' => [
                    'ar' => 'منطقة جدة المضلعة 1',
                    'en' => 'Jeddah Polygon Zone 1',
                ],
                'description' => [
                    'ar' => 'منطقة مضلعة تغطي شمال جدة',
                    'en' => 'Polygon zone covering North Jeddah',
                ],
                'points' => [
                    ['latitude' => 21.390, 'longitude' => 39.855],
                    ['latitude' => 21.395, 'longitude' => 39.860],
                    ['latitude' => 21.385, 'longitude' => 39.865],
                    ['latitude' => 21.380, 'longitude' => 39.860],
                ],
                'is_active' => true,
                'delivery_fee' => 9.00,
                'minimum_order' => 40.00,
            ],
            [
                'name' => [
                    'ar' => 'منطقة الدمام المضلعة 1',
                    'en' => 'Dammam Polygon Zone 1',
                ],
                'description' => [
                    'ar' => 'منطقة مضلعة تغطي شرق الدمام',
                    'en' => 'Polygon zone covering East Dammam',
                ],
                'points' => [
                    ['latitude' => 26.421, 'longitude' => 50.087],
                    ['latitude' => 26.423, 'longitude' => 50.090],
                    ['latitude' => 26.418, 'longitude' => 50.092],
                    ['latitude' => 26.416, 'longitude' => 50.088],
                ],
                'is_active' => true,
                'delivery_fee' => 7.50,
                'minimum_order' => 30.00,
            ],
            [
                'name' => [
                    'ar' => 'منطقة المدينة المنورة المضلعة 1',
                    'en' => 'Medina Polygon Zone 1',
                ],
                'description' => [
                    'ar' => 'منطقة مضلعة تغطي غرب المدينة المنورة',
                    'en' => 'Polygon zone covering West Medina',
                ],
                'points' => [
                    ['latitude' => 24.467, 'longitude' => 39.598],
                    ['latitude' => 24.470, 'longitude' => 39.602],
                    ['latitude' => 24.465, 'longitude' => 39.605],
                    ['latitude' => 24.462, 'longitude' => 39.600],
                ],
                'is_active' => true,
                'delivery_fee' => 6.50,
                'minimum_order' => 25.00,
            ],
            [
                'name' => [
                    'ar' => 'منطقة أبها المضلعة 1',
                    'en' => 'Abha Polygon Zone 1',
                ],
                'description' => [
                    'ar' => 'منطقة مضلعة تغطي جنوب أبها',
                    'en' => 'Polygon zone covering South Abha',
                ],
                'points' => [
                    ['latitude' => 18.215, 'longitude' => 42.504],
                    ['latitude' => 18.218, 'longitude' => 42.507],
                    ['latitude' => 18.213, 'longitude' => 42.509],
                    ['latitude' => 18.210, 'longitude' => 42.506],
                ],
                'is_active' => true,
                'delivery_fee' => 5.50,
                'minimum_order' => 20.00,
            ],
        ];

        foreach ($zones as $zoneData) {
            Zone::create([
                'name' => $zoneData['name'],
                'description' => $zoneData['description'],
                'points' => $zoneData['points'],
                'is_active' => $zoneData['is_active'],
                'delivery_fee' => $zoneData['delivery_fee'],
                'minimum_order' => $zoneData['minimum_order'],
            ]);
        }
    }
}
