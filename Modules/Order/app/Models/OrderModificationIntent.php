<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staged order total increase awaiting surcharge payment.
 *
 * Created when an edit adds items to a gateway-paid order. The order's
 * total_amount / final_amount are only updated once the surcharge is paid
 * (see PaymentController::callback). Until then the order keeps its original
 * total, so an unpaid surcharge never inflates what the customer owes.
 */
class OrderModificationIntent extends Model
{
    protected $fillable = [
        'order_id',
        'delta_amount',
        'new_total',
        'new_final_amount',
        'staged_pricing',
        'status',
        'payment_transaction_id',
        'resolved_at',
    ];

    protected $casts = [
        'delta_amount' => 'decimal:2',
        'new_total' => 'decimal:2',
        'new_final_amount' => 'decimal:2',
        'staged_pricing' => 'array',
        'resolved_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_EXPIRED = 'expired';

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(\Modules\Payment\Models\PaymentTransaction::class, 'payment_transaction_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
