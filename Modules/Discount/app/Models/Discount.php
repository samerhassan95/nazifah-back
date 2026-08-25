<?php

namespace Modules\Discount\Models;

use App\Traits\Cacheable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Client\Models\Client;
use Modules\Vendor\Models\Vendor;
use Modules\Zone\Models\Zone;
use Spatie\Translatable\HasTranslations;

class Discount extends Model
{
    use Cacheable;
    use HasFactory, HasTranslations, SoftDeletes;

    protected static function booted()
    {
        static::saved(function ($model) {
            flushCacheTags(['branches', 'services', 'discounts']);
        });

        static::deleted(function ($model) {
            flushCacheTags(['branches', 'services', 'discounts']);
        });
    }

    protected $fillable = [
        'code',
        'name',
        'description',
        'type',
        'discount_type',
        'promotion_domain',
        'promotion_kind',
        'is_automatic',
        'priority',
        'funding_source',
        'usage_condition',
        'usage_service_ids',
        'application_scope',
        'discount_service_ids',
        'category_ids',
        'value',
        'min_order_amount',
        'min_items_count',
        'min_repeat_orders',
        'first_order_only',
        'applies_to_delivery',
        'delivery_discount_type',
        'max_discount_amount',
        'min_wallet_topup_amount',
        'wallet_bonus_amount',
        'wallet_bonus_percent',
        'start_date',
        'end_date',
        'active_days_of_week',
        'active_time_from',
        'active_time_to',
        'usage_limit',
        'used_count',
        'is_active',
        'applicable_to',
        'user_ids',
        'group_ids',
        'branch_ids',
        'city_names',
        'zone_ids',
        'segment_filters',
        'required_piece_ids',
        'bundle_rules',
        'metadata',
    ];

    public $translatable = ['name', 'description'];

    protected $casts = [
        'value' => 'decimal:2',
        'min_order_amount' => 'decimal:2',
        'min_wallet_topup_amount' => 'decimal:2',
        'wallet_bonus_amount' => 'decimal:2',
        'wallet_bonus_percent' => 'decimal:2',
        'max_discount_amount' => 'decimal:2',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'active_time_from' => 'datetime:H:i:s',
        'active_time_to' => 'datetime:H:i:s',
        'usage_limit' => 'integer',
        'used_count' => 'integer',
        'is_active' => 'boolean',
        'is_automatic' => 'boolean',
        'priority' => 'integer',
        'min_items_count' => 'integer',
        'min_repeat_orders' => 'integer',
        'first_order_only' => 'boolean',
        'applies_to_delivery' => 'boolean',
        'user_ids' => 'array',
        'group_ids' => 'array',
        'usage_service_ids' => 'array',
        'discount_service_ids' => 'array',
        'category_ids' => 'array',
        'active_days_of_week' => 'array',
        'branch_ids' => 'array',
        'city_names' => 'array',
        'zone_ids' => 'array',
        'segment_filters' => 'array',
        'required_piece_ids' => 'array',
        'bundle_rules' => 'array',
        'metadata' => 'array',
    ];

    const TYPE_PERCENTAGE = 'percentage';

    const TYPE_FIXED = 'fixed';

    const DOMAIN_ORDER = 'order';

    const DOMAIN_WALLET_TOPUP = 'wallet_topup';

    const DISCOUNT_TYPE_DELIVERY_FREE = 'delivery_free';

    const DISCOUNT_TYPE_VENDORS = 'vendors';

    const DISCOUNT_TYPE_ZONE = 'zone';

    const DISCOUNT_TYPE_CLIENT = 'client';

    const DISCOUNT_TYPE_GLOBAL = 'global';

    const KIND_FIRST_ORDER = 'first_order';

    const KIND_SERVICE_SCOPE = 'service_scope';

    const KIND_QUANTITY_THRESHOLD = 'quantity_threshold';

    const KIND_ORDER_TOTAL_THRESHOLD = 'order_total_threshold';

    const KIND_DELIVERY_DISCOUNT = 'delivery_discount';

    const KIND_REPEAT_ORDER = 'repeat_order';

    const KIND_BRANCH_SCOPE = 'branch_scope';

    const KIND_CITY_SCOPE = 'city_scope';

    const KIND_TIME_WINDOW = 'time_window';

    const KIND_CUSTOMER_SEGMENT = 'customer_segment';

    const KIND_VENDOR_FUNDED = 'vendor_funded';

    const KIND_BUNDLE = 'bundle';

    const KIND_WALLET_TOPUP_BONUS = 'wallet_topup_bonus';

    const USAGE_CONDITION_ALL = 'all';

    const USAGE_CONDITION_SERVICES = 'services';

    const APPLICATION_SCOPE_ORDER_TOTAL = 'order_total';

    const APPLICATION_SCOPE_SERVICES = 'services';

    /**
     * Vendors this discount applies to
     */
    public function vendors(): BelongsToMany
    {
        return $this->morphedByMany(Vendor::class, 'model', 'discount_model', 'discount_id', 'model_id')
            ->withTimestamps();
    }

    /**
     * Zones this discount applies to
     */
    public function zones(): BelongsToMany
    {
        return $this->morphedByMany(Zone::class, 'model', 'discount_model', 'discount_id', 'model_id')
            ->withTimestamps();
    }

    /**
     * Clients this discount applies to
     */
    public function clients(): BelongsToMany
    {
        return $this->morphedByMany(Client::class, 'model', 'discount_model', 'discount_id', 'model_id')
            ->withTimestamps();
    }

    /**
     * Scope a query to only include active discounts.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now());
    }

    /**
     * Scope a query to only include expired discounts.
     */
    public function scopeExpired($query)
    {
        return $query->where('end_date', '<', now());
    }

    public function scopeDomain($query, string $domain)
    {
        return $query->where('promotion_domain', $domain);
    }

    public function scopeAutomatic($query)
    {
        return $query->where('is_automatic', true);
    }

    public function scopeManual($query)
    {
        return $query->where('is_automatic', false);
    }

    public function normalizedPromotionDomain(): string
    {
        return (string) ($this->promotion_domain ?: self::DOMAIN_ORDER);
    }

    public function normalizedPromotionKind(): string
    {
        $kind = (string) ($this->promotion_kind ?: '');
        if ($kind !== '') {
            return $kind;
        }

        return match ((string) $this->discount_type) {
            self::DISCOUNT_TYPE_DELIVERY_FREE => self::KIND_DELIVERY_DISCOUNT,
            default => self::KIND_ORDER_TOTAL_THRESHOLD,
        };
    }
}
