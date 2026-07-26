<?php

namespace Modules\Address\Database\Seeders;

use Illuminate\Database\Seeder;

class AddressSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $zoneIds = \Modules\Zone\Models\Zone::pluck('id')->toArray();
        for ($i = 1; $i <= 10; $i++) {
            \Modules\Address\Models\Address::create([
                'client_id' => ($i % 10) + 1,
                'title' => $i % 2 == 0 ? 'Home' : 'Work',
                'street_name' => 'King Fahd Street '.$i,
                'building_number' => 'B'.$i,
                'street_number' => 'S'.($i * 10),
                'floor' => rand(1, 10),
                'apartment' => rand(1, 50),
                'latitude' => 24.7136 + (rand(-100, 100) / 1000),
                'longitude' => 46.6753 + (rand(-100, 100) / 1000),
                'notes' => 'Near landmark '.$i,
                'is_default' => $i % 3 == 0,
                'zone_id' => $zoneIds[array_rand($zoneIds)],
            ]);
        }
    }
}
