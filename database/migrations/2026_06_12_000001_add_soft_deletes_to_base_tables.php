<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft deletes for main business entity tables (not pivots, logs, or financial ledgers).
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'admins',
        'clients',
        'client_cards',
        'payment_cards',
        'vendors',
        'vendor_employees',
        'vendor_bank_accounts',
        'vendor_withdrawal_requests',
        'drivers',
        'owners',
        'branches',
        'branch_working_hour_shifts',
        'pieces',
        'services',
        'service_additions',
        'categories',
        'zones',
        'addresses',
        'orders',
        'order_items',
        'order_item_additions',
        'order_item_additional_services',
        'pending_orders',
        'icons',
        'ads',
        'banner_offers',
        'discounts',
        'conversations',
        'messages',
        'notifications',
        'marketing_notifications',
        'whatsapp_templates',
        'whatsapp_groups',
        'whatsapp_campaigns',
        'subscription_plans',
        'vendor_subscriptions',
        'payment_methods',
        'roles',
        'permissions',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropSoftDeletes();
            });
        }
    }
};
