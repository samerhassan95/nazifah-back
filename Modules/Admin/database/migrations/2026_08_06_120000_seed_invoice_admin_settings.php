<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Invoice\Services\InvoiceSettingsService;

return new class extends Migration
{
    public function up(): void
    {
        app(InvoiceSettingsService::class)->seedDefaults();
    }

    public function down(): void
    {
        DB::table('admin_settings')->whereIn('key', array_keys(app(InvoiceSettingsService::class)->definitions()))->delete();
    }
};
