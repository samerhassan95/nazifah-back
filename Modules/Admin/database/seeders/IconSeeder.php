<?php

namespace Modules\Admin\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Admin\Models\Icon;

class IconSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $icons = [
            [
                'name' => 'Washing Icon',
                'description' => 'Icon for washing services',
                'path' => '/storage/icons/washing.svg',
            ],
            [
                'name' => 'Ironing Icon',
                'description' => 'Icon for ironing services',
                'path' => '/storage/icons/ironing.svg',
            ],
            [
                'name' => 'Dry Cleaning Icon',
                'description' => 'Icon for dry cleaning services',
                'path' => '/storage/icons/dry-cleaning.svg',
            ],
            [
                'name' => 'Shirt Icon',
                'description' => 'Icon for shirt pieces',
                'path' => '/storage/icons/shirt.svg',
            ],
            [
                'name' => 'Pants Icon',
                'description' => 'Icon for pants pieces',
                'path' => '/storage/icons/pants.svg',
            ],
            [
                'name' => 'Dress Icon',
                'description' => 'Icon for dress pieces',
                'path' => '/storage/icons/dress.svg',
            ],
            [
                'name' => 'Suit Icon',
                'description' => 'Icon for suit pieces',
                'path' => '/storage/icons/suit.svg',
            ],
            [
                'name' => 'Stain Removal Icon',
                'description' => 'Icon for stain removal additional service',
                'path' => '/storage/icons/stain-removal.svg',
            ],
            [
                'name' => 'Express Service Icon',
                'description' => 'Icon for express service',
                'path' => '/storage/icons/express.svg',
            ],
            [
                'name' => 'Folding Icon',
                'description' => 'Icon for folding service',
                'path' => '/storage/icons/folding.svg',
            ],
        ];

        foreach ($icons as $icon) {
            Icon::create($icon);
        }
    }
}
