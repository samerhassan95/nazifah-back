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
        'value',
        'min_order_amount',
        'max_discount_amount',
        'start_date',
        'end_date',
        'usage_limit',
        'used_count',
        'is_active',
        'applicable_to',
        'user_ids',
        'group_ids',
    ];

    public $translatable = ['name', 'description'];

    protected $casts = [
        'value' => 'decimal:2',
        'min_order_amount' => 'decimal:2',
        'max_discount_amount' => 'decimal:2',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'usage_limit' => 'integer',
        'used_count' => 'integer',
        'is_active' => 'boolean',
        'user_ids' => 'array',
        'group_ids' => 'array',
    ];

    const TYPE_PERCENTAGE = 'percentage';

    const TYPE_FIXED = 'fixed';

    const DISCOUNT_TYPE_DELIVERY_FREE = 'delivery_free';

    const DISCOUNT_TYPE_VENDORS = 'vendors';

    const DISCOUNT_TYPE_ZONE = 'zone';

    const DISCOUNT_TYPE_CLIENT = 'client';

    const DISCOUNT_TYPE_GLOBAL = 'global';

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
}
