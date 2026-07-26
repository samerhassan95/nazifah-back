<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add branch_id to drivers table and remove vendor_id
        Schema::table('drivers', function (Blueprint $table) {
            if (! Schema::hasColumn('drivers', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->onDelete('set null');
            }
            if (Schema::hasColumn('drivers', 'vendor_id')) {
                $table->dropForeign(['vendor_id']);
                $table->dropColumn('vendor_id');
            }
        });

        // Drop the old many-to-many pivot table
        Schema::dropIfExists('branch_driver');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate the pivot table
        Schema::create('branch_driver', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('driver_id')->constrained('drivers')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['branch_id', 'driver_id']);
        });

        // Restore vendor_id and remove branch_id
        Schema::table('drivers', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('id')->constrained('vendors')->onDelete('set null');
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
        });
    }
};
