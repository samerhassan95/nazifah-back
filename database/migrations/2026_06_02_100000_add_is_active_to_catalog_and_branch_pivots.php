<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pieces', 'is_active')) {
            Schema::table('pieces', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('icon_id');
            });
        }

        if (! Schema::hasColumn('service_additions', 'is_active')) {
            Schema::table('service_additions', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('icon_id');
            });
        }

        if (! Schema::hasColumn('branch_service', 'is_active')) {
            Schema::table('branch_service', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('service_id');
            });
        }

        if (! Schema::hasColumn('branch_piece', 'is_active')) {
            Schema::table('branch_piece', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('piece_id');
            });
        }

        if (! Schema::hasColumn('branch_service_addition', 'is_active')) {
            Schema::table('branch_service_addition', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('service_addition_id');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'pieces' => 'is_active',
            'service_additions' => 'is_active',
            'branch_service' => 'is_active',
            'branch_piece' => 'is_active',
            'branch_service_addition' => 'is_active',
        ] as $table => $column) {
            if (Schema::hasColumn($table, $column)) {
                Schema::table($table, function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
