<?php

namespace Modules\Category\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Category\Models\Category;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => ['ar' => 'غسيل ملابس', 'en' => 'Laundry'], 'icon_id' => null, 'order' => 1],
            ['name' => ['ar' => 'كي ملابس', 'en' => 'Ironing'], 'icon_id' => null, 'order' => 2],
            ['name' => ['ar' => 'تنظيف جاف', 'en' => 'Dry Cleaning'], 'icon_id' => null, 'order' => 3],
            ['name' => ['ar' => 'تنظيف سجاد', 'en' => 'Carpet Cleaning'], 'icon_id' => null, 'order' => 4],
            ['name' => ['ar' => 'تنظيف ستائر', 'en' => 'Curtain Cleaning'], 'icon_id' => null, 'order' => 5],
        ];

        foreach ($categories as $category) {
            Category::create($category);
        }
    }
}
