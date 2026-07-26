<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_piece', function (Blueprint $table) {
            // Add the new unique index FIRST so MySQL can use it for the FK on
            // service_id before the old index is dropped (avoids MySQL error 1553).
            $table->unique(
                ['service_id', 'piece_id', 'branch_id'],
                'service_piece_branch_unique'
            );
            $table->dropUnique('service_piece_unique');
        });
    }

    public function down(): void
    {
        Schema::table('service_piece', function (Blueprint $table) {
            $table->unique(['service_id', 'piece_id'], 'service_piece_unique');
            $table->dropUnique('service_piece_branch_unique');
        });
    }
};
