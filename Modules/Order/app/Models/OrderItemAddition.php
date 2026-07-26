<?php

namespace Modules\Order\Models;

use App\Traits\Cacheable;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Service\Models\ServiceAddition;

class OrderItemAddition extends Model
{
    use Cacheable;
    use HasFactory;
    use HasSoftDeletes;

    protected $fillable = [
        'order_item_id',
        'service_addition_id',
        'quantity',
        'unit_price',
        'total_price',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
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
