<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'client_postpone_reason')) {
                $table->text('client_postpone_reason')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('orders', 'client_postponed_at')) {
                $table->timestamp('client_postponed_at')->nullable()->after('client_postpone_reason');
            }
            if (! Schema::hasColumn('orders', 'client_visit_confirmed_at')) {
                $table->timestamp('client_visit_confirmed_at')->nullable()->after('client_postponed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $columns = ['client_postpone_reason', 'client_postponed_at', 'client_visit_confirmed_at'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
