<?php

namespace Modules\Admin\Database\Seeders;

use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admins = [
            ['phone' => '966500000001', 'name' => 'Admin', 'email' => 'admin@example.com'],
            ['phone' => '966500000002', 'name' => 'Admin 2', 'email' => 'admin2@example.com'],
            ['phone' => '966500000003', 'name' => 'Admin 3', 'email' => 'admin3@example.com'],
        ];

        foreach ($admins as $adminData) {

            \Modules\Admin\Models\Admin::create([
                'phone' => $adminData['phone'],
                'name' => $adminData['name'],
                'email' => $adminData['email'],
                'password' => bcrypt('password123'),
                'is_verified' => true,
            ]);

            $this->command->info("Admin '{$adminData['name']}' created with password: password123");
        }
    }
}
