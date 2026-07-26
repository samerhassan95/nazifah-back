<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Updates all latitude and longitude columns to support 12 decimal places
     * Using decimal(20, 12) to ensure no data truncation
     * This allows values like 27.25445 or 47.675325445858
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        // Temporarily disable strict mode to allow column modification
        $strictMode = \DB::select('SELECT @@sql_mode as mode')[0]->mode;
        \DB::statement("SET SESSION sql_mode=''");

        try {
            // Update branches table
            if (Schema::hasTable('branches')) {
                Schema::table('branches', function (Blueprint $table) {
                    if (Schema::hasColumn('branches', 'latitude')) {
                        $table->decimal('latitude', 20, 12)->change();
                    }
                    if (Schema::hasColumn('branches', 'longitude')) {
                        $table->decimal('longitude', 20, 12)->change();
                    }
                });
            }

            // Update vendors table
            if (Schema::hasTable('vendors')) {
                Schema::table('vendors', function (Blueprint $table) {
                    if (Schema::hasColumn('vendors', 'latitude')) {
                        $table->decimal('latitude', 20, 12)->nullable()->change();
                    }
                    if (Schema::hasColumn('vendors', 'longitude')) {
                        $table->decimal('longitude', 20, 12)->nullable()->change();
                    }
                });
            }

            // Update addresses table
            if (Schema::hasTable('addresses')) {
                Schema::table('addresses', function (Blueprint $table) {
                    if (Schema::hasColumn('addresses', 'latitude')) {
                        $table->decimal('latitude', 20, 12)->change();
                    }
                    if (Schema::hasColumn('addresses', 'longitude')) {
                        $table->decimal('longitude', 20, 12)->change();
                    }
                });
            }

            // Update clients table
            if (Schema::hasTable('clients')) {
                Schema::table('clients', function (Blueprint $table) {
                    if (Schema::hasColumn('clients', 'latitude')) {
                        $table->decimal('latitude', 20, 12)->nullable()->change();
                    }
                    if (Schema::hasColumn('clients', 'longitude')) {
                        $table->decimal('longitude', 20, 12)->nullable()->change();
                    }
                });
            }

            // Update drivers table
            if (Schema::hasTable('drivers')) {
                Schema::table('drivers', function (Blueprint $table) {
                    if (Schema::hasColumn('drivers', 'latitude')) {
                        $table->decimal('latitude', 20, 12)->nullable()->change();
                    }
                    if (Schema::hasColumn('drivers', 'longitude')) {
                        $table->decimal('longitude', 20, 12)->nullable()->change();
                    }
                });
            }

            // Update zones table (center coordinates)
            if (Schema::hasTable('zones')) {
                Schema::table('zones', function (Blueprint $table) {
                    if (Schema::hasColumn('zones', 'center_latitude')) {
                        $table->decimal('center_latitude', 20, 12)->nullable()->change();
                    }
                    if (Schema::hasColumn('zones', 'center_longitude')) {
                        $table->decimal('center_longitude', 20, 12)->nullable()->change();
                    }
                });
            }
        } finally {
            // Restore original SQL mode
            DB::statement("SET SESSION sql_mode='$strictMode'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        // Revert branches table
        if (Schema::hasTable('branches')) {
            Schema::table('branches', function (Blueprint $table) {
                if (Schema::hasColumn('branches', 'latitude')) {
                    $table->decimal('latitude', 10, 8)->change();
                }
                if (Schema::hasColumn('branches', 'longitude')) {
                    $table->decimal('longitude', 11, 8)->change();
                }
            });
        }

        // Revert vendors table
        if (Schema::hasTable('vendors')) {
            Schema::table('vendors', function (Blueprint $table) {
                if (Schema::hasColumn('vendors', 'latitude')) {
                    $table->decimal('latitude', 10, 8)->nullable()->change();
                }
                if (Schema::hasColumn('vendors', 'longitude')) {
                    $table->decimal('longitude', 11, 8)->nullable()->change();
                }
            });
        }

        // Revert addresses table
        if (Schema::hasTable('addresses')) {
            Schema::table('addresses', function (Blueprint $table) {
                if (Schema::hasColumn('addresses', 'latitude')) {
                    $table->decimal('latitude', 10, 8)->change();
                }
                if (Schema::hasColumn('addresses', 'longitude')) {
                    $table->decimal('longitude', 11, 8)->change();
                }
            });
        }

        // Revert clients table
        if (Schema::hasTable('clients')) {
            Schema::table('clients', function (Blueprint $table) {
                if (Schema::hasColumn('clients', 'latitude')) {
                    $table->decimal('latitude', 10, 8)->nullable()->change();
                }
                if (Schema::hasColumn('clients', 'longitude')) {
                    $table->decimal('longitude', 11, 8)->nullable()->change();
                }
            });
        }

        // Revert drivers table
        if (Schema::hasTable('drivers')) {
            Schema::table('drivers', function (Blueprint $table) {
                if (Schema::hasColumn('drivers', 'latitude')) {
                    $table->decimal('latitude', 10, 8)->nullable()->change();
                }
                if (Schema::hasColumn('drivers', 'longitude')) {
                    $table->decimal('longitude', 11, 8)->nullable()->change();
                }
            });
        }

        // Revert zones table
        if (Schema::hasTable('zones')) {
            Schema::table('zones', function (Blueprint $table) {
                if (Schema::hasColumn('zones', 'center_latitude')) {
                    $table->decimal('center_latitude', 10, 8)->nullable()->change();
                }
                if (Schema::hasColumn('zones', 'center_longitude')) {
                    $table->decimal('center_longitude', 11, 8)->nullable()->change();
                }
            });
        }
    }
};
