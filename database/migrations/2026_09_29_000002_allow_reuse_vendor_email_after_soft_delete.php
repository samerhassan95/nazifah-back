<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropUnique('vendors_email_unique');
            $table->string('active_email')
                ->nullable()
                ->storedAs('CASE WHEN deleted_at IS NULL THEN email ELSE NULL END');
            $table->unique('active_email', 'vendors_active_email_unique');
        });

        Schema::table('vendor_employees', function (Blueprint $table) {
            $table->dropUnique('vendor_employees_email_unique');
            $table->string('active_email')
                ->nullable()
                ->storedAs('CASE WHEN deleted_at IS NULL THEN email ELSE NULL END');
            $table->unique('active_email', 'vendor_employees_active_email_unique');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropUnique('vendors_active_email_unique');
            $table->dropColumn('active_email');
            $table->unique('email', 'vendors_email_unique');
        });

        Schema::table('vendor_employees', function (Blueprint $table) {
            $table->dropUnique('vendor_employees_active_email_unique');
            $table->dropColumn('active_email');
            $table->unique('email', 'vendor_employees_email_unique');
        });
    }
};
