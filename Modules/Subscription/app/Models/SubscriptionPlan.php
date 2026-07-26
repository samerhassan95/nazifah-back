<?php

namespace Modules\Subscription\Models;

use App\Traits\Cacheable;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class SubscriptionPlan extends Model
{
    use Cacheable;
    use HasFactory, HasTranslations;
    use HasSoftDeletes;

    protected static function booted()
    {
        static::saved(fn () => self::clearCache());
        static::deleted(fn () => self::clearCache());
    }

    public static function clearCache(): void
    {
        flushCacheTags(['subscription_plans']);
    }

    protected $fillable = [
        'name',
        'tagline',
        'price_month',
        'price_year',
        'currency',
        'is_featured',
        'has_discount',
        'discount_percentage',
        'branch_count',
        'order_count',
        'has_discount_codes',
        'has_special_delivery',
        'has_reports',
        'is_active',
    ];

    public array $translatable = ['name', 'tagline'];

    protected $casts = [
        'price_month' => 'decimal:2',
        'price_year' => 'decimal:2',
        'is_featured' => 'boolean',
        'has_discount' => 'boolean',
        'discount_percentage' => 'integer',
        'branch_count' => 'integer',
        'order_count' => 'integer',
        'has_discount_codes' => 'boolean',
        'has_special_delivery' => 'boolean',
        'has_reports' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Get all vendor subscriptions for this plan
     */
    public function vendorSubscriptions(): HasMany
    {
        return $this->hasMany(VendorSubscription::class);
    }

    /**
     * Get active vendor subscriptions for this plan
     */
    public function activeVendorSubscriptions(): HasMany
    {
        return $this->vendorSubscriptions()->where('status', 'active');
    }
}
