<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order numbers are switching back to ORD-YYYYMMDD-##### (prefix + date +
 * a per-day sequential count), reverting the brief "plain integer" format
 * from 40da41c. date_key tracks which day the counter's next_value belongs
 * to — generateUniqueOrderNumber() resets next_value to 1 whenever it finds
 * date_key isn't today, under the same row lock that already makes the
 * counter race-free (see 2026_09_08_000002_create_order_number_sequences_table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_number_sequences', function (Blueprint $table) {
            $table->string('date_key', 8)->nullable()->after('next_value');
        });
    }

    public function down(): void
    {
        Schema::table('order_number_sequences', function (Blueprint $table) {
            $table->dropColumn('date_key');
        });
    }
};
