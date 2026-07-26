<?php

namespace Modules\Branch\Database\Seeders;

use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $branches = [
            ['name' => ['ar' => 'فرع الرياض', 'en' => 'Riyadh Branch'], 'phone_number' => '966112345678', 'land_phone' => '101', 'location' => ['ar' => 'الرياض، حي العليا', 'en' => 'Riyadh, Olaya'], 'latitude' => '24.7136', 'longitude' => '46.6753', 'description' => ['ar' => 'فرع رئيسي', 'en' => 'Main branch']],
            ['name' => ['ar' => 'فرع الملقا', 'en' => 'Malqa Branch'], 'phone_number' => '966112345679', 'land_phone' => '102', 'location' => ['ar' => 'الرياض، حي الملقا', 'en' => 'Riyadh, Malqa'], 'latitude' => '24.7636', 'longitude' => '46.6253', 'description' => ['ar' => 'فرع شمالي', 'en' => 'Northern branch']],
            ['name' => ['ar' => 'فرع جدة', 'en' => 'Jeddah Branch'], 'phone_number' => '966122345678', 'land_phone' => '201', 'location' => ['ar' => 'جدة، حي الحمرا', 'en' => 'Jeddah, Al Hamra'], 'latitude' => '21.5433', 'longitude' => '39.1728', 'description' => ['ar' => 'فرع جدة', 'en' => 'Jeddah branch']],
            ['name' => ['ar' => 'فرع الدمام', 'en' => 'Dammam Branch'], 'phone_number' => '966132345678', 'location' => ['ar' => 'الدمام، حي الكورنيش', 'en' => 'Dammam, Corniche'], 'latitude' => '26.4207', 'longitude' => '50.0888', 'description' => ['ar' => 'فرع الدمام', 'en' => 'Dammam branch']],
        ];

        foreach ($branches as $branch) {
            \Modules\Branch\Models\Branch::create($branch);
        }
    }
}
