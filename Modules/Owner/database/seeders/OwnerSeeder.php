<?php

namespace Modules\Owner\Database\Seeders;

use Illuminate\Database\Seeder;

class OwnerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $password = \Illuminate\Support\Str::password(16);

            \Modules\Owner\Models\Owner::create([
                'name' => ['ar' => 'مالك '.$i, 'en' => 'Owner '.$i],
                'email' => 'owner'.$i.'@example.com',
                'phone' => '96656'.str_pad($i, 7, '0', STR_PAD_LEFT),
                'whatsapp' => '96656'.str_pad($i, 7, '0', STR_PAD_LEFT),
                'password' => bcrypt($password),
                'is_verified' => true,
            ]);

            $this->command->info("Owner {$i} created with password: {$password}");
        }
    }
}
