<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_service', function (Blueprint $table) {
            $table->json('name')->nullable()->after('service_id');
        });
    }

    public function down(): void
    {
        Schema::table('branch_service', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
