<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            if (! Schema::hasColumn('discounts', 'promotion_domain')) {
                $table->string('promotion_domain')->default('order')->after('discount_type');
            }
            if (! Schema::hasColumn('discounts', 'promotion_kind')) {
                $table->string('promotion_kind')->nullable()->after('promotion_domain');
            }
            if (! Schema::hasColumn('discounts', 'is_automatic')) {
                $table->boolean('is_automatic')->default(false)->after('promotion_kind');
            }
            if (! Schema::hasColumn('discounts', 'priority')) {
                $table->integer('priority')->default(100)->after('is_automatic');
            }
            if (! Schema::hasColumn('discounts', 'funding_source')) {
                $table->string('funding_source')->default('platform')->after('priority');
            }
            if (! Schema::hasColumn('discounts', 'min_items_count')) {
                $table->integer('min_items_count')->nullable()->after('min_order_amount');
            }
            if (! Schema::hasColumn('discounts', 'min_repeat_orders')) {
                $table->integer('min_repeat_orders')->nullable()->after('min_items_count');
            }
            if (! Schema::hasColumn('discounts', 'first_order_only')) {
                $table->boolean('first_order_only')->default(false)->after('min_repeat_orders');
            }
            if (! Schema::hasColumn('discounts', 'applies_to_delivery')) {
                $table->boolean('applies_to_delivery')->default(false)->after('first_order_only');
            }
            if (! Schema::hasColumn('discounts', 'delivery_discount_type')) {
                $table->string('delivery_discount_type')->nullable()->after('applies_to_delivery');
            }
            if (! Schema::hasColumn('discounts', 'min_wallet_topup_amount')) {
                $table->decimal('min_wallet_topup_amount', 10, 2)->nullable()->after('delivery_discount_type');
            }
            if (! Schema::hasColumn('discounts', 'wallet_bonus_amount')) {
                $table->decimal('wallet_bonus_amount', 10, 2)->nullable()->after('min_wallet_topup_amount');
            }
            if (! Schema::hasColumn('discounts', 'wallet_bonus_percent')) {
                $table->decimal('wallet_bonus_percent', 8, 2)->nullable()->after('wallet_bonus_amount');
            }
            if (! Schema::hasColumn('discounts', 'active_days_of_week')) {
                $table->json('active_days_of_week')->nullable()->after('end_date');
            }
            if (! Schema::hasColumn('discounts', 'active_time_from')) {
                $table->time('active_time_from')->nullable()->after('active_days_of_week');
            }
            if (! Schema::hasColumn('discounts', 'active_time_to')) {
                $table->time('active_time_to')->nullable()->after('active_time_from');
            }
            if (! Schema::hasColumn('discounts', 'branch_ids')) {
                $table->json('branch_ids')->nullable()->after('group_ids');
            }
            if (! Schema::hasColumn('discounts', 'city_names')) {
                $table->json('city_names')->nullable()->after('branch_ids');
            }
            if (! Schema::hasColumn('discounts', 'segment_filters')) {
                $table->json('segment_filters')->nullable()->after('city_names');
            }
            if (! Schema::hasColumn('discounts', 'required_piece_ids')) {
                $table->json('required_piece_ids')->nullable()->after('segment_filters');
            }
            if (! Schema::hasColumn('discounts', 'bundle_rules')) {
                $table->json('bundle_rules')->nullable()->after('required_piece_ids');
            }
            if (! Schema::hasColumn('discounts', 'metadata')) {
                $table->json('metadata')->nullable()->after('bundle_rules');
            }
        });
    }

    public function down(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            $columns = [];

            foreach ([
                'promotion_domain',
                'promotion_kind',
                'is_automatic',
                'priority',
                'funding_source',
                'min_items_count',
                'min_repeat_orders',
                'first_order_only',
                'applies_to_delivery',
                'delivery_discount_type',
                'min_wallet_topup_amount',
                'wallet_bonus_amount',
                'wallet_bonus_percent',
                'active_days_of_week',
                'active_time_from',
                'active_time_to',
                'branch_ids',
                'city_names',
                'segment_filters',
                'required_piece_ids',
                'bundle_rules',
                'metadata',
            ] as $column) {
                if (Schema::hasColumn('discounts', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
