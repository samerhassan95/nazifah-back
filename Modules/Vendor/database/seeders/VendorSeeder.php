<?php

namespace Modules\Vendor\Database\Seeders;

use Illuminate\Database\Seeder;

class VendorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            \Modules\Vendor\Models\Vendor::create([
                'name' => ['ar' => 'مغسلة '.$i, 'en' => 'Laundry '.$i],
                'email' => 'vendor'.$i.'@example.com',
                'phone' => '96650'.str_pad($i, 7, '0', STR_PAD_LEFT),
                'official_number' => 'CR'.str_pad($i * 1000, 10, '0', STR_PAD_LEFT),
                'logo' => 'images/vendors/logo.png',
                'vat_number' => 'VAT'.str_pad($i * 1000, 10, '0', STR_PAD_LEFT),
                'delivery_price_per_km' => 10,
                'is_active' => true,
                'is_banned' => false,
                'ban_reason' => null,
                'banned_at' => null,
            ]);
        }
    }
}
