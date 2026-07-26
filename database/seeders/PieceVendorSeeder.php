<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Piece\Models\Piece;
use Modules\Vendor\Models\Vendor;

class PieceVendorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $vendors = Vendor::all();
        $pieces = Piece::all();

        if ($vendors->isEmpty()) {
            $this->command->warn('No vendors found. Skipping piece-vendor assignment.');

            return;
        }

        if ($pieces->isEmpty()) {
            $this->command->warn('No pieces found. Skipping piece-vendor assignment.');

            return;
        }

        // Assign random vendor to each piece
        foreach ($pieces as $piece) {
            $piece->update([
                'vendor_id' => $vendors->random()->id,
            ]);
        }

        $this->command->info('Pieces assigned to vendors successfully!');
    }
}
