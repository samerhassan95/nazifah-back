<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Make email nullable if it's not already
            if (Schema::hasColumn('clients', 'email')) {
                $table->string('email')->nullable()->change();
            }

            // Make full_name nullable if it's not already
            if (Schema::hasColumn('clients', 'full_name')) {
                $table->text('full_name')->nullable()->change();
            }

            // Make location nullable if it's not already
            if (Schema::hasColumn('clients', 'location')) {
                $table->text('location')->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Revert changes if needed
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
            $table->text('full_name')->nullable(false)->change();
            $table->text('location')->nullable(false)->change();
        });
    }
};
