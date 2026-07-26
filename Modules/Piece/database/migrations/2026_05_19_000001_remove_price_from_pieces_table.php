<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('pieces', 'price')) {
            Schema::table('pieces', function (Blueprint $table) {
                $table->dropColumn('price');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pieces', 'price')) {
            Schema::table('pieces', function (Blueprint $table) {
                $table->decimal('price', 10, 2)->nullable()->after('icon_id');
            });
        }
    }
};
