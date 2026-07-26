<?php

namespace Modules\Piece\Database\Seeders;

use Illuminate\Database\Seeder;

class PieceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $pieces = [
            ['name' => ['ar' => 'قميص', 'en' => 'Shirt'], 'icon_id' => null, 'vendor_id' => 1],
            ['name' => ['ar' => 'بنطلون', 'en' => 'Pants'], 'icon_id' => null, 'vendor_id' => 1],
            ['name' => ['ar' => 'جلابية', 'en' => 'Thobe'], 'icon_id' => null, 'vendor_id' => 1],
            ['name' => ['ar' => 'فستان', 'en' => 'Dress'], 'icon_id' => null, 'vendor_id' => 1],
            ['name' => ['ar' => 'بدلة رجالي', 'en' => 'Men Suit'], 'icon_id' => null, 'vendor_id' => 1],
            ['name' => ['ar' => 'معطف', 'en' => 'Coat'], 'icon_id' => null, 'vendor_id' => 1],
        ];

        foreach ($pieces as $piece) {
            \Modules\Piece\Models\Piece::create($piece);
        }
    }
}
