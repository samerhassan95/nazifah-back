<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('admin_settings', function (Blueprint $table) {
            $table->string('type')->default('text')->after('key'); // text, number, boolean, json, image, select
        });

        // Update existing settings to have type
        $this->updateExistingSettings();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admin_settings', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }

    /**
     * Update existing settings with appropriate types
     */
    private function updateExistingSettings(): void
    {
        // You can update existing settings types here if needed
        // Example:
        // DB::table('admin_settings')->where('key', 'some_number_setting')->update(['type' => 'number']);
    }

    /**
     * Seed default settings
     */
    private function seedDefaultSettings(): void
    {
        $settings = [
            // Platform Settings
            ['key' => 'platform_name_ar', 'type' => 'text', 'value' => 'نظيفة'],
            ['key' => 'platform_name_en', 'type' => 'text', 'value' => 'Nathefah'],
            ['key' => 'platform_logo', 'type' => 'image', 'value' => null],
            ['key' => 'default_language', 'type' => 'text', 'value' => 'ar'],
            ['key' => 'default_currency', 'type' => 'text', 'value' => 'SAR'],
            ['key' => 'default_timezone', 'type' => 'text', 'value' => 'Asia/Riyadh'],

            // Order Settings
            ['key' => 'min_order_amount', 'type' => 'number', 'value' => '50'],
            ['key' => 'max_order_amount', 'type' => 'number', 'value' => '10000'],
            ['key' => 'delivery_fee', 'type' => 'number', 'value' => '15'],
            ['key' => 'free_delivery_threshold', 'type' => 'number', 'value' => '200'],
            ['key' => 'order_cancellation_time', 'type' => 'number', 'value' => '30'],

            // Notification Settings
            ['key' => 'enable_push_notifications', 'type' => 'boolean', 'value' => 'true'],
            ['key' => 'enable_email_notifications', 'type' => 'boolean', 'value' => 'true'],
            ['key' => 'enable_sms_notifications', 'type' => 'boolean', 'value' => 'false'],

            // Maintenance Settings
            ['key' => 'maintenance_mode', 'type' => 'boolean', 'value' => 'false'],
            ['key' => 'maintenance_message_ar', 'type' => 'text', 'value' => 'المنصة تحت الصيانة حالياً'],
            ['key' => 'maintenance_message_en', 'type' => 'text', 'value' => 'Platform is under maintenance'],

            // Contact Settings
            ['key' => 'support_email', 'type' => 'text', 'value' => 'support@nathefah.com'],
            ['key' => 'support_phone', 'type' => 'text', 'value' => '+966500000000'],
            ['key' => 'support_whatsapp', 'type' => 'text', 'value' => '+966500000000'],
        ];

        foreach ($settings as $setting) {
            DB::table('admin_settings')->updateOrInsert(
                ['key' => $setting['key']],
                array_merge($setting, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
};
