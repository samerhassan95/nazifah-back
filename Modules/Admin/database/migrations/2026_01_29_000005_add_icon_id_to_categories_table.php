<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // Drop old icon column
            if (Schema::hasColumn('categories', 'icon')) {
                $table->dropColumn('icon');
            }
            // Add new icon_id foreign key
            $table->foreignId('icon_id')->nullable()->constrained('icons')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['icon_id']);
            $table->dropColumn('icon_id');
            // Restore old icon column
            $table->string('icon')->nullable();
        });
    }
};
