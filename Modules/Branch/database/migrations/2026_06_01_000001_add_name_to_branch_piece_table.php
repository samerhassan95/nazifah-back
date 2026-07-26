<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branch-specific display name for a vendor piece (catalog row in pieces is unchanged).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_piece', function (Blueprint $table) {
            $table->json('name')->nullable()->after('piece_id');
        });
    }

    public function down(): void
    {
        Schema::table('branch_piece', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
