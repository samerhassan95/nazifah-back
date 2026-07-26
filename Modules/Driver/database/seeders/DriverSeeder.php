<?php

namespace Modules\Driver\Database\Seeders;

use Illuminate\Database\Seeder;

class DriverSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $branches = \Modules\Branch\Models\Branch::all();
        foreach ($branches as $branch) {
            \Modules\Driver\Models\Driver::create([
                'vendor_id' => $branch->vendor_id,
                'branch_id' => $branch->id,
                'full_name' => ['ar' => 'سائق الفرع '.$branch->id, 'en' => 'Driver Branch '.$branch->id],
                'phone' => '96655'.str_pad($branch->id, 7, '0', STR_PAD_LEFT),
                'email' => 'driver_b'.$branch->id.'@example.com',
                'latitude' => 24.7136 + ($branch->id * 0.01),
                'longitude' => 46.6753 + ($branch->id * 0.01),
                'is_available' => true,
            ]);
        }
    }
}
