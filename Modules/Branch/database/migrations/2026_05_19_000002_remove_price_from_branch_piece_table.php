<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('branch_piece', 'price')) {
            Schema::table('branch_piece', function (Blueprint $table) {
                $table->dropColumn('price');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('branch_piece', 'price')) {
            Schema::table('branch_piece', function (Blueprint $table) {
                $table->decimal('price', 10, 2)->nullable()->after('piece_id');
            });
        }
    }
};
