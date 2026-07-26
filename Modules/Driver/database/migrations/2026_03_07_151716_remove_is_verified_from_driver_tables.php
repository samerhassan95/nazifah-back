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
        if (Schema::hasColumn('drivers', 'is_verified')) {
            try {
                Schema::table('drivers', function (Blueprint $table) {
                    $table->dropIndex(['phone', 'is_verified']);
                });
            } catch (\Exception $e) {
                // Index might not exist
            }

            Schema::table('drivers', function (Blueprint $table) {
                $table->dropColumn('is_verified');
            });
        }

        if (Schema::hasColumn('driver_auth_sessions', 'is_verified')) {
            try {
                Schema::table('driver_auth_sessions', function (Blueprint $table) {
                    $table->dropIndex(['phone', 'is_verified']);
                });
            } catch (\Exception $e) {
                // Index might not exist
            }

            Schema::table('driver_auth_sessions', function (Blueprint $table) {
                $table->dropColumn('is_verified');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->boolean('is_verified')->default(false);
            $table->index(['phone', 'is_verified']);
        });

        Schema::table('driver_auth_sessions', function (Blueprint $table) {
            $table->boolean('is_verified')->default(false);
            $table->index(['phone', 'is_verified']);
        });
    }
};
