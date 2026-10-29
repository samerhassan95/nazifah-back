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
            if (! Schema::hasColumn('orders', 'client_receipt_reminder_24h_sent_at')) {
                $table->timestamp('client_receipt_reminder_24h_sent_at')->nullable()->after('active_handoff_declared_at');
            }
            if (! Schema::hasColumn('orders', 'client_receipt_reminder_48h_sent_at')) {
                $table->timestamp('client_receipt_reminder_48h_sent_at')->nullable()->after('client_receipt_reminder_24h_sent_at');
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
                'client_receipt_reminder_24h_sent_at',
                'client_receipt_reminder_48h_sent_at',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
