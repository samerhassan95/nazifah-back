<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('vendor_employees')
            ->whereNull('deleted_at')
            ->whereIn('vendor_id', function ($query) {
                $query->select('id')
                    ->from('vendors')
                    ->whereNotNull('deleted_at');
            })
            ->update(['deleted_at' => now()]);

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropUnique('vendors_phone_unique');
            $table->string('active_phone')
                ->nullable()
                ->storedAs('CASE WHEN deleted_at IS NULL THEN phone ELSE NULL END');
            $table->unique('active_phone', 'vendors_active_phone_unique');
        });

        Schema::table('vendor_employees', function (Blueprint $table) {
            $table->dropUnique('vendor_employees_phone_unique');
            $table->string('active_phone')
                ->nullable()
                ->storedAs('CASE WHEN deleted_at IS NULL THEN phone ELSE NULL END');
            $table->unique('active_phone', 'vendor_employees_active_phone_unique');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropUnique('vendors_active_phone_unique');
            $table->dropColumn('active_phone');
            $table->unique('phone', 'vendors_phone_unique');
        });

        Schema::table('vendor_employees', function (Blueprint $table) {
            $table->dropUnique('vendor_employees_active_phone_unique');
            $table->dropColumn('active_phone');
            $table->unique('phone', 'vendor_employees_phone_unique');
        });
    }
};
