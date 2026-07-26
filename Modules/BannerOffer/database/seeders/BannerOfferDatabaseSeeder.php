<?php

namespace Modules\BannerOffer\Database\Seeders;

use Illuminate\Database\Seeder;

class BannerOfferDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            BannerOfferSeeder::class,
        ]);
    }
}
