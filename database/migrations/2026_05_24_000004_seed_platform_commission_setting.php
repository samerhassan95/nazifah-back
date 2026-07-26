<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('admin_settings')
            ->where('key', 'platform_commission_percentage')
            ->exists();

        if (! $exists) {
            DB::table('admin_settings')->insert([
                'key' => 'platform_commission_percentage',
                'type' => 'number',
                'value' => '15',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('admin_settings')
            ->where('key', 'platform_commission_percentage')
            ->delete();
    }
};
