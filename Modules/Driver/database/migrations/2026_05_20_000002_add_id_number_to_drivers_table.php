<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('drivers', 'id_number')) {
            Schema::table('drivers', function (Blueprint $table) {
                $table->string('id_number', 50)->nullable()->after('phone');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('drivers', 'id_number')) {
            Schema::table('drivers', function (Blueprint $table) {
                $table->dropColumn('id_number');
            });
        }
    }
};
