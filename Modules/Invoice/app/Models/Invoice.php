<?php

namespace Modules\Invoice\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_REPORTED = 'reported';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SENT_WHATSAPP = 'sent_whatsapp';

    protected $fillable = [
        'order_id',
        'payment_transaction_id',
        'client_id',
        'vendor_id',
        'branch_id',
        'invoice_number',
        'invoice_type',
        'currency',
        'status',
        'zatca_status',
        'whatsapp_status',
        'zatca_uuid',
        'zatca_reference',
        'zatca_invoice_hash',
        'zatca_qr_code',
        'subtotal_amount',
        'discount_amount',
        'tax_amount',
        'delivery_fee',
        'total_amount',
        'customer_name',
        'customer_phone',
        'customer_email',
        'seller_name',
        'seller_vat_number',
        'seller_registration_number',
        'seller_address',
        'issued_at',
        'reported_at',
        'whatsapp_sent_at',
        'invoice_payload',
        'provider_payload',
        'provider_response',
        'last_error',
    ];

    protected $casts = [
        'subtotal_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'issued_at' => 'datetime',
        'reported_at' => 'datetime',
        'whatsapp_sent_at' => 'datetime',
        'invoice_payload' => 'array',
        'provider_payload' => 'array',
        'provider_response' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(\Modules\Order\Models\Order::class);
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(\Modules\Payment\Models\PaymentTransaction::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(\Modules\Client\Models\Client::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(\Modules\Vendor\Models\Vendor::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(\Modules\Branch\Models\Branch::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(InvoiceAttempt::class);
    }
}
