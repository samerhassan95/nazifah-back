<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropUnique('drivers_phone_unique');
            $table->string('active_phone')
                ->nullable()
                ->storedAs('CASE WHEN deleted_at IS NULL THEN phone ELSE NULL END');
            $table->unique('active_phone', 'drivers_active_phone_unique');
        });
    }

    public function down(): void
    {
        $hasDuplicatePhones = DB::table('drivers')
            ->select('phone')
            ->groupBy('phone')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicatePhones) {
            throw new RuntimeException('Cannot restore the unique driver phone index while duplicate phone numbers exist.');
        }

        Schema::table('drivers', function (Blueprint $table) {
            $table->dropUnique('drivers_active_phone_unique');
            $table->dropColumn('active_phone');
            $table->unique('phone', 'drivers_phone_unique');
        });
    }
};
