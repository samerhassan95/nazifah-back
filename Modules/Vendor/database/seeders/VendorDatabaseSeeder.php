<?php

namespace Modules\Vendor\Database\Seeders;

use Illuminate\Database\Seeder;

class VendorDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            VendorSeeder::class,
        ]);
    }
}
