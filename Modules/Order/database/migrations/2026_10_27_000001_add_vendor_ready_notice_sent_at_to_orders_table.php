<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'vendor_ready_notice_sent_at')) {
                $table->timestamp('vendor_ready_notice_sent_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'vendor_ready_notice_sent_at')) {
                $table->dropColumn('vendor_ready_notice_sent_at');
            }
        });
    }
};
