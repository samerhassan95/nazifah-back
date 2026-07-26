<?php

namespace Modules\Service\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Category\Models\Category;
use Modules\Piece\Models\Piece;
use Modules\Service\Models\Service;
use Modules\Vendor\Models\Vendor;

class ServiceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $vendors = Vendor::all();
        $categories = Category::all();

        $categoryMap = [];
        foreach ($categories as $cat) {
            $categoryMap[$cat->getTranslation('name', 'en')] = $cat->id;
            $categoryMap[$cat->getTranslation('name', 'ar')] = $cat->id;
        }

        $services = [
            ['service_name' => ['ar' => 'غسيل عادي', 'en' => 'Regular Wash'], 'category_id' => $categoryMap['Laundry'] ?? 1, 'price' => 15.00],
            ['service_name' => ['ar' => 'غسيل سريع', 'en' => 'Express Wash'], 'category_id' => $categoryMap['Laundry'] ?? 1, 'price' => 25.00],
            ['service_name' => ['ar' => 'كي قمصان', 'en' => 'Shirt Ironing'], 'category_id' => $categoryMap['Ironing'] ?? 2, 'price' => 5.00],
            ['service_name' => ['ar' => 'تنظيف جاف للبدل', 'en' => 'Suit Dry Cleaning'], 'category_id' => $categoryMap['Dry Cleaning'] ?? 3, 'price' => 50.00],
            ['service_name' => ['ar' => 'تنظيف سجاد صغير', 'en' => 'Small Carpet'], 'category_id' => $categoryMap['Carpet Cleaning'] ?? 4, 'price' => 80.00],
            ['service_name' => ['ar' => 'غسيل فساتين', 'en' => 'Dress Washing'], 'category_id' => $categoryMap['Laundry'] ?? 1, 'price' => 20.00],
            ['service_name' => ['ar' => 'كي بناطيل', 'en' => 'Pants Ironing'], 'category_id' => $categoryMap['Ironing'] ?? 2, 'price' => 8.00],
            ['service_name' => ['ar' => 'تنظيف ستائر', 'en' => 'Curtain Cleaning'], 'category_id' => $categoryMap['Curtain Cleaning'] ?? 5, 'price' => 30.00],
        ];

        foreach ($vendors as $vendor) {
            foreach ($services as $serviceData) {
                $service = Service::create($serviceData);

                $allPieceIds = Piece::pluck('id')->toArray();
                if (! empty($allPieceIds)) {
                    $syncData = [];
                    foreach ($allPieceIds as $pid) {
                        $syncData[$pid] = ['price' => $service->price ?? 0.00];
                    }
                    $service->pieces()->sync($syncData);
                }
            }
        }
    }
}
