<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add category_id to pieces table so each piece is linked to its
 * section/category (from 04_Pieces.Section_ID in the master catalog).
 * This allows filtering pieces by the category the user selected
 * before choosing a service in the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pieces', function (Blueprint $table) {
            $table->foreignId('category_id')
                ->nullable()
                ->after('vendor_id')
                ->constrained('categories')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('pieces', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });
    }
};
