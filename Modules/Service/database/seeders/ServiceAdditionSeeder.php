<?php

namespace Modules\Service\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Service\Models\ServiceAddition;
use Modules\Vendor\Models\Vendor;

class ServiceAdditionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $vendors = Vendor::all();

        $serviceAdditions = [
            [
                'name' => ['ar' => 'معطر إضافي', 'en' => 'Extra Fragrance'],
                'price' => 3.00,
                'icon_id' => null,
            ],
            [
                'name' => ['ar' => 'كي سريع', 'en' => 'Express Ironing'],
                'price' => 8.00,
                'icon_id' => null,
            ],
            [
                'name' => ['ar' => 'تغليف خاص', 'en' => 'Special Packaging'],
                'price' => 5.00,
                'icon_id' => null,
            ],
            [
                'name' => ['ar' => 'إزالة البقع', 'en' => 'Stain Removal'],
                'price' => 10.00,
                'icon_id' => null,
            ],
            [
                'name' => ['ar' => 'حماية من العث', 'en' => 'Moth Protection'],
                'price' => 7.00,
                'icon_id' => null,
            ],
            [
                'name' => ['ar' => 'تنعيم الأقمشة', 'en' => 'Fabric Softening'],
                'price' => 4.00,
                'icon_id' => null,
            ],
        ];

        foreach ($vendors as $vendor) {
            foreach ($serviceAdditions as $addition) {
                ServiceAddition::create([
                    'vendor_id' => $vendor->id,
                    'name' => $addition['name'],
                    'price' => $addition['price'],
                    'icon_id' => $addition['icon_id'],
                ]);
            }
        }
    }
}
