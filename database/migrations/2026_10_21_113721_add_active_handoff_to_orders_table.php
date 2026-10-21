<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'active_handoff_type')) {
                $table->string('active_handoff_type', 50)->nullable()->after('client_delivery_handoff_at');
            }
            if (! Schema::hasColumn('orders', 'active_handoff_declared_by_role')) {
                $table->string('active_handoff_declared_by_role', 20)->nullable()->after('active_handoff_type');
            }
            if (! Schema::hasColumn('orders', 'active_handoff_declared_by_id')) {
                $table->unsignedBigInteger('active_handoff_declared_by_id')->nullable()->after('active_handoff_declared_by_role');
            }
            if (! Schema::hasColumn('orders', 'active_handoff_declared_at')) {
                $table->timestamp('active_handoff_declared_at')->nullable()->after('active_handoff_declared_by_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $columns = [
                'active_handoff_type',
                'active_handoff_declared_by_role',
                'active_handoff_declared_by_id',
                'active_handoff_declared_at',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
