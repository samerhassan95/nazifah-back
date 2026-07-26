<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // Core user tables
            \Modules\Admin\Database\Seeders\AdminDatabaseSeeder::class,
            \Modules\Client\Database\Seeders\ClientDatabaseSeeder::class,
            \Modules\Owner\Database\Seeders\OwnerDatabaseSeeder::class,
            \Modules\Vendor\Database\Seeders\VendorDatabaseSeeder::class,
            \Modules\Driver\Database\Seeders\DriverDatabaseSeeder::class,

            // Category and services
            \Modules\Category\Database\Seeders\CategoryDatabaseSeeder::class,
            // Pieces must be seeded before services so service seeder can attach pieces
            \Modules\Piece\Database\Seeders\PieceDatabaseSeeder::class,
            \Modules\Service\Database\Seeders\ProductDatabaseSeeder::class, // Includes ServiceAddition and Service

            // Zones and locations
            \Modules\Zone\Database\Seeders\ZoneDatabaseSeeder::class,

            // Vendor-related
            \Modules\Branch\Database\Seeders\BranchDatabaseSeeder::class,

            // Client-related
            \Modules\Address\Database\Seeders\AddressDatabaseSeeder::class,

            // Orders
            \Modules\Order\Database\Seeders\OrderDatabaseSeeder::class,

            // Marketing
            \Modules\Discount\Database\Seeders\DiscountDatabaseSeeder::class,
            \Modules\BannerOffer\Database\Seeders\BannerOfferDatabaseSeeder::class,
            \Modules\Ad\Database\Seeders\AdDatabaseSeeder::class,

            // Notifications
            \Modules\Notification\Database\Seeders\NotificationDatabaseSeeder::class,

            // Payment
            \Modules\Payment\Database\Seeders\PaymentDatabaseSeeder::class,

        ]);
    }
}
