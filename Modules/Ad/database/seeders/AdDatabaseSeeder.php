<?php

namespace Modules\Ad\Database\Seeders;

use Illuminate\Database\Seeder;

class AdDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            AdSeeder::class,
        ]);
    }
}
