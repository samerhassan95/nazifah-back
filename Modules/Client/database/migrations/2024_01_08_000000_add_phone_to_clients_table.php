<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Add phone column if it doesn't exist
            if (! Schema::hasColumn('clients', 'phone')) {
                $table->string('phone')->unique()->after('id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'phone')) {
                $table->dropColumn('phone');
            }
        });
    }
};
