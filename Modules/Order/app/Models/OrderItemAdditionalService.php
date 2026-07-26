<?php

namespace Modules\Order\Models;

use App\Traits\Cacheable;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Service\Models\ServiceAddition;

class OrderItemAdditionalService extends Model
{
    use Cacheable;
    use HasSoftDeletes;

    protected $fillable = [
        'order_item_id',
        'service_addition_id',
        'price',
        'quantity',
        'vendor_status',
        'vendor_notes',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'quantity' => 'integer',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function serviceAddition(): BelongsTo
    {
        return $this->belongsTo(ServiceAddition::class);
    }
}
