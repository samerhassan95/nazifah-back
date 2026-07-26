<?php

namespace Modules\Client\Models;

use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Payment\Models\PaymentTransaction;

class ClientCard extends Model
{
    use HasSoftDeletes;

    protected $fillable = [
        'client_id',
        'payfort_token_name',
        'card_brand',
        'card_holder_name',
        'last_four',
        'expiry_date',
        'is_default',
        'last_used_at',
        'source_payment_transaction_id',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function sourcePaymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'source_payment_transaction_id');
    }

    public function maskedNumber(): string
    {
        return '**** **** **** '.$this->last_four;
    }
}
