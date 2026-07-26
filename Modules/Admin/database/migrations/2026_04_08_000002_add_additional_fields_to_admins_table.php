<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            if (! Schema::hasColumn('admins', 'image')) {
                $table->string('image')->nullable()->after('phone');
            }
            if (! Schema::hasColumn('admins', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('is_verified');
            }
            if (! Schema::hasColumn('admins', 'permissions')) {
                $table->json('permissions')->nullable()->after('last_login_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn(['image', 'last_login_at', 'permissions']);
        });
    }
};
