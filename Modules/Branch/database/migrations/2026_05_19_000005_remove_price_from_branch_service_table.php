<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('branch_service', 'price')) {
            Schema::table('branch_service', function (Blueprint $table) {
                $table->dropColumn('price');
            });
        }
    }

    public function down(): void
    {
        Schema::table('branch_service', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable()->after('service_id');
        });
    }
};
