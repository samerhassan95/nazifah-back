<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Driver\Models\Driver;

class OrderDriverRejection extends Model
{
    use HasFactory;

    public const TYPE_PICKUP = 'pickup';

    public const TYPE_DELIVERY = 'delivery';

    protected $table = 'order_driver_rejections';

    protected $fillable = [
        'order_id',
        'driver_id',
        'trip_type',
        'reason',
        'rejected_at',
    ];

    protected $casts = [
        'rejected_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function isPickup(): bool
    {
        return $this->trip_type === self::TYPE_PICKUP;
    }

    public function isDelivery(): bool
    {
        return $this->trip_type === self::TYPE_DELIVERY;
    }
}
