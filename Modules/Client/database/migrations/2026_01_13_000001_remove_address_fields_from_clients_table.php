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
        Schema::table('clients', function (Blueprint $table) {
            $columns = ['street_name', 'building_number', 'street_number', 'latitude', 'longitude', 'location'];

            foreach ($columns as $column) {
                if (Schema::hasColumn('clients', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'street_name')) {
                $table->string('street_name')->nullable();
            }
            if (! Schema::hasColumn('clients', 'building_number')) {
                $table->string('building_number')->nullable();
            }
            if (! Schema::hasColumn('clients', 'street_number')) {
                $table->string('street_number')->nullable();
            }
            if (! Schema::hasColumn('clients', 'latitude')) {
                $table->decimal('latitude', 10, 8)->nullable();
            }
            if (! Schema::hasColumn('clients', 'longitude')) {
                $table->decimal('longitude', 11, 8)->nullable();
            }
            if (! Schema::hasColumn('clients', 'location')) {
                $table->json('location')->nullable();
            }
        });
    }
};
