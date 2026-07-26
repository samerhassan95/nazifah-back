<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            if (! Schema::hasColumn('addresses', 'zone_id')) {
                $table->foreignId('zone_id')->nullable()->after('client_id')->constrained('zones')->onDelete('set null');
            }
            if (! Schema::hasColumn('addresses', 'address_text')) {
                $table->string('address_text')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('addresses', 'address_label')) {
                $table->string('address_label')->nullable()->after('title');
            }
            if (! Schema::hasColumn('addresses', 'city')) {
                $table->string('city')->nullable()->after('street_name');
            }
            if (! Schema::hasColumn('addresses', 'district')) {
                $table->string('district')->nullable()->after('city');
            }
            if (! Schema::hasColumn('addresses', 'postal_code')) {
                $table->string('postal_code')->nullable()->after('district');
            }
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropForeign(['zone_id']);
            $table->dropColumn(['zone_id', 'address_text', 'address_label', 'city', 'district', 'postal_code']);
        });
    }
};
