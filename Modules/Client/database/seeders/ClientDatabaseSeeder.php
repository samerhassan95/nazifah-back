<?php

namespace Modules\Client\Database\Seeders;

use Database\Factories\ClientFactory;
use Illuminate\Database\Seeder;
use Modules\Client\Models\Client;

class ClientDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
        ]);

        // Create 50 random clients
        ClientFactory::new()->count(50)->create();

        // Create some specific test clients
        Client::create([
            'phone' => '9660512345678',
            'full_name' => 'مستخدم تجريبي',
            'email' => 'test@example.com',
            'is_verified' => true,
        ]);

        Client::create([
            'phone' => '966501234567',
            'full_name' => 'أحمد محمد',
            'email' => 'ahmed@example.com',
            'is_verified' => true,
        ]);

        // Create some unverified clients
        Client::create([
            'phone' => '966571234567',
            'full_name' => 'محمد خالد',
            'is_verified' => false,
        ]);

    }
}
