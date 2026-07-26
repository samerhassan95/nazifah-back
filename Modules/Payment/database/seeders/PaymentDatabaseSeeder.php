<?php

namespace Modules\Payment\Database\Seeders;

use Illuminate\Database\Seeder;

class PaymentDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Seed can be added later for test payment transactions if needed
        $this->command->info('Payment module seeded successfully.');
    }
}
