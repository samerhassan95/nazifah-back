<?php

namespace Modules\Piece\Database\Seeders;

use Illuminate\Database\Seeder;

class PieceDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            PieceSeeder::class,
        ]);
    }
}
