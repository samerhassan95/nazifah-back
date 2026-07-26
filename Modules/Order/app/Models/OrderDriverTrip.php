<?php

namespace Modules\Order\Models;

use App\Traits\Cacheable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Driver\Models\Driver;

class OrderDriverTrip extends Model
{
    use Cacheable;
    use HasFactory;

    public const TYPE_PICKUP = 'pickup';

    public const TYPE_DELIVERY = 'delivery';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    protected $table = 'order_driver_trips';

    protected $fillable = [
        'order_id',
        'driver_id',
        'trip_type',
        'status',
        'completed_at',
        'delivery_fee',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'delivery_fee' => 'float',
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

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
