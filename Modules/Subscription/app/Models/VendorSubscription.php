<?php

namespace Modules\Subscription\Models;

use App\Traits\Cacheable;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Vendor\Models\Vendor;

class VendorSubscription extends Model
{
    use Cacheable;
    use HasFactory;
    use HasSoftDeletes;

    protected $fillable = [
        'vendor_id',
        'subscription_plan_id',
        'billing_cycle',
        'amount',
        'payment_method',
        'subscription_date',
        'expiry_date',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'subscription_date' => 'date',
        'expiry_date' => 'date',
    ];

    const STATUS_ACTIVE = 'active';

    const STATUS_EXPIRED = 'expired';

    const STATUS_BANNED = 'banned';

    const STATUS_CANCELLED = 'cancelled';

    const BILLING_CYCLE_MONTHLY = 'monthly';

    const BILLING_CYCLE_YEARLY = 'yearly';

    /**
     * Get the vendor that owns this subscription
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Get the subscription plan
     */
    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    /**
     * Check if subscription is active
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->expiry_date >= now()->toDateString();
    }

    /**
     * Check if subscription is expired
     */
    public function isExpired(): bool
    {
        return $this->expiry_date < now()->toDateString();
    }
}
