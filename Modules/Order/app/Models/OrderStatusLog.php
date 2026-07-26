<?php

namespace Modules\Order\Models;

use App\Enums\OrderStatus;
use App\Traits\Cacheable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStatusLog extends Model
{
    use Cacheable;
    use HasFactory;

    protected $appends = [
        'status_label',
    ];

    protected $fillable = [
        'order_id',
        'status',
        'notes',
        'changed_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the localized label for the status
     */
    public function getStatusLabelAttribute(): string
    {
        $status = OrderStatus::tryFrom($this->status);

        return $status ? $status->localizedLabel($this->order?->payment_method) : $this->status;
    }

    /**
     * Get the color for the status
     */
    public function getStatusColorAttribute(): string
    {
        $status = OrderStatus::tryFrom($this->status);
        return $status ? $status->color() : '#808080';
    }

    /**
     * Get the icon for the status
     */
    public function getStatusIconAttribute(): string
    {
        $status = OrderStatus::tryFrom($this->status);

        return $status ? $status->icon() : '❓';
    }
}
