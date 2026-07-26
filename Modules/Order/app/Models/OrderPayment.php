<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One leg of an order's payment.
 *
 * An order may be settled by several legs paid with different methods
 * (e.g. part from the wallet + the remainder on a card, or two separate card
 * transactions). The order is fully paid once the sum of its `paid` legs
 * covers final_amount.
 */
class OrderPayment extends Model
{
    protected $fillable = [
        'order_id',
        'payment_transaction_id',
        'modification_intent_id',
        'payment_method',
        'amount',
        'status',
        'sequence',
        'is_surcharge',
        'meta',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'sequence' => 'integer',
        'is_surcharge' => 'boolean',
        'meta' => 'array',
        'paid_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(\Modules\Payment\Models\PaymentTransaction::class, 'payment_transaction_id');
    }

    public function modificationIntent(): BelongsTo
    {
        return $this->belongsTo(OrderModificationIntent::class, 'modification_intent_id');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
