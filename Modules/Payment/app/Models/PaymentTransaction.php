<?php

namespace Modules\Payment\Models;

use App\Traits\Cacheable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    use Cacheable;
    use HasFactory;

    protected $fillable = [
        'order_id',
        'gateway',
        'transaction_id',
        'fort_id',
        'payfort_token_name',
        'amount',
        'authorized_amount',
        'currency',
        'status',
        'payment_method',
        'is_additional_charge',
        'authorization_type',
        'customer_email',
        'customer_name',
        'customer_phone',
        'response_data',
        'error_message',
        'paid_at',
        'refunded_at',
        'refund_amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'authorized_amount' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'is_additional_charge' => 'boolean',
        'response_data' => 'array',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    /**
     * Get the order that owns the payment
     */
    public function order()
    {
        return $this->belongsTo(\Modules\Order\Models\Order::class);
    }

    /**
     * Get payment refunds
     */
    public function refunds()
    {
        return $this->hasMany(PaymentRefund::class);
    }

    /**
     * Check if payment is successful
     */
    public function isSuccessful(): bool
    {
        return in_array($this->status, ['completed', 'authorized']);
    }

    /**
     * Check if payment is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if payment is failed
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if payment is refunded
     */
    public function isRefunded(): bool
    {
        return in_array($this->status, ['refunded', 'partially_refunded']);
    }

    /**
     * Resolve the PayFort payment_option (STCPAY, VISA, MADA, ...) for this
     * transaction. Capture / void / refund use this to route to the correct
     * merchant account & credentials (STC Pay settles on a separate merchant).
     */
    public function payfortPaymentOption(): ?string
    {
        if ($this->payment_method) {
            $method = \App\Enums\PaymentMethod::tryFrom($this->payment_method);
            if ($method) {
                return $method->getPayfortPaymentOption();
            }
        }

        return $this->response_data['payment_option'] ?? null;
    }
}
