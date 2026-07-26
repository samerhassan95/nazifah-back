<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // First, clear any existing sessions (they're temporary anyway)
        DB::table('vendor_auth_sessions')->truncate();

        // Now change the column type
        Schema::table('vendor_auth_sessions', function (Blueprint $table) {
            $table->json('name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('vendor_auth_sessions', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
        });
    }
};
