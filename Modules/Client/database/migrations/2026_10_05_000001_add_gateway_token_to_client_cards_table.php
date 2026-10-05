<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_cards', function (Blueprint $table) {
            $table->string('payfort_token_name')->nullable()->change();
            $table->string('gateway')->nullable()->after('payfort_token_name');
            $table->string('gateway_token')->nullable()->unique()->after('gateway');
        });
    }

    public function down(): void
    {
        Schema::table('client_cards', function (Blueprint $table) {
            $table->dropUnique(['gateway_token']);
            $table->dropColumn(['gateway', 'gateway_token']);
            $table->string('payfort_token_name')->nullable(false)->change();
        });
    }
};
