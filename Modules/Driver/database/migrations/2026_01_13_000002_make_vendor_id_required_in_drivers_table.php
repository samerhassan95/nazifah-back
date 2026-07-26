<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Make vendor_id required (not nullable) in drivers table.
     */
    public function up(): void
    {
        // Automatically assign vendor_id to drivers that don't have one
        // Try to get vendor_id from their orders (through branch) or branches
        DB::table('drivers')
            ->whereNull('vendor_id')
            ->chunkById(100, function ($drivers) {
                foreach ($drivers as $driver) {
                    $vendorId = null;

                    // Try to get vendor_id from driver's orders (through branch)
                    $vendorId = DB::table('orders')
                        ->join('branches', 'orders.branch_id', '=', 'branches.id')
                        ->where('orders.driver_id', $driver->id)
                        ->whereNotNull('branches.vendor_id')
                        ->select('branches.vendor_id')
                        ->first()
                        ?->vendor_id;

                    // If not found, try to get vendor_id from driver's branches (through branch_driver pivot)
                    if (! $vendorId) {
                        $vendorId = DB::table('branch_driver')
                            ->join('branches', 'branch_driver.branch_id', '=', 'branches.id')
                            ->where('branch_driver.driver_id', $driver->id)
                            ->whereNotNull('branches.vendor_id')
                            ->select('branches.vendor_id')
                            ->first()
                            ?->vendor_id;
                    }

                    // If still not found, get the first vendor as fallback
                    if (! $vendorId) {
                        $vendorId = DB::table('vendors')
                            ->orderBy('id')
                            ->value('id');
                    }

                    // Update the driver with the found vendor_id
                    if ($vendorId) {
                        DB::table('drivers')
                            ->where('id', $driver->id)
                            ->update(['vendor_id' => $vendorId]);
                    }
                }
            });

        // Final check - if any drivers still don't have vendor_id, throw an error
        $driversWithoutVendor = DB::table('drivers')
            ->whereNull('vendor_id')
            ->count();

        if ($driversWithoutVendor > 0) {
            throw new \Exception(
                "Cannot make vendor_id required: {$driversWithoutVendor} driver(s) still have null vendor_id. ".
                'No vendor could be determined from their orders or branches, and no vendors exist in the system.'
            );
        }

        Schema::table('drivers', function (Blueprint $table) {
            // Drop the existing foreign key
            $table->dropForeign(['vendor_id']);

            // Modify the column to be non-nullable
            $table->foreignId('vendor_id')->nullable(false)->change();

            // Re-add the foreign key constraint
            $table->foreign('vendor_id')
                ->references('id')
                ->on('vendors')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            // Drop the foreign key
            $table->dropForeign(['vendor_id']);

            // Make vendor_id nullable again
            $table->foreignId('vendor_id')->nullable()->change();

            // Re-add the foreign key constraint
            $table->foreign('vendor_id')
                ->references('id')
                ->on('vendors')
                ->onDelete('cascade');
        });
    }
};
