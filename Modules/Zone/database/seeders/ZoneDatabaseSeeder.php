<?php

namespace Modules\Zone\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Address\Database\Seeders\AddressSeeder;

class ZoneDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            ZoneSeeder::class,
            AddressSeeder::class,
        ]);
    }
}
