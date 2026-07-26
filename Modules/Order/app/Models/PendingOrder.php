<?php

namespace Modules\Order\Models;

use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Client\Models\Client;

class PendingOrder extends Model
{
    use HasSoftDeletes;

    protected $fillable = [
        'client_id',
        'vendor_id',
        'order_data',
        'items_data',
        'discount_id',
        'payment_method',
        'status',
        'expires_at',
    ];

    protected $casts = [
        'order_data' => 'array',
        'items_data' => 'array',
        'expires_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Scope: pending orders whose expiry has passed and haven't been processed yet.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', 'pending')
            ->where('expires_at', '<=', now());
    }

    /**
     * Whether this pending order has expired without being completed.
     */
    public function isExpired(): bool
    {
        return $this->status === 'pending'
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }
}
