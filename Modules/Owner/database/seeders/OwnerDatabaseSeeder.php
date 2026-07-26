<?php

namespace Modules\Owner\Database\Seeders;

use Illuminate\Database\Seeder;

class OwnerDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            OwnerSeeder::class,
        ]);
    }
}
