<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_methods')) {
            return;
        }

        $exists = DB::table('payment_methods')
            ->where('method_key', 'credit_card')
            ->exists();

        if ($exists) {
            return;
        }

        $row = [
            'method_key' => 'credit_card',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('payment_methods', 'sort_order')) {
            $minSort = (int) (DB::table('payment_methods')->min('sort_order') ?? 1);
            $row['sort_order'] = max(1, $minSort);
        }

        DB::table('payment_methods')->insert($row);
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_methods')) {
            return;
        }

        DB::table('payment_methods')
            ->where('method_key', 'credit_card')
            ->delete();
    }
};
