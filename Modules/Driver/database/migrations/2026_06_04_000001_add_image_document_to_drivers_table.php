<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('drivers', 'image_document')) {
            Schema::table('drivers', function (Blueprint $table) {
                $table->string('image_document')->nullable()->after('id_number');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('drivers', 'image_document')) {
            Schema::table('drivers', function (Blueprint $table) {
                $table->dropColumn('image_document');
            });
        }
    }
};
