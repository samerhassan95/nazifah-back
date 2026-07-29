<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_service', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('icon_id');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_service', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
