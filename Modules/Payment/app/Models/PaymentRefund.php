<?php

namespace Modules\Payment\Models;

use App\Traits\Cacheable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentRefund extends Model
{
    use Cacheable;
    use HasFactory;

    protected $fillable = [
        'payment_transaction_id',
        'refund_id',
        'amount',
        'currency',
        'status',
        'reason',
        'response_data',
        'processed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'response_data' => 'array',
        'processed_at' => 'datetime',
    ];

    /**
     * Get the payment transaction
     */
    public function paymentTransaction()
    {
        return $this->belongsTo(PaymentTransaction::class);
    }
}
