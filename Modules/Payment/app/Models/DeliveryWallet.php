<?php

namespace Modules\Payment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Driver\Models\Driver;

class DeliveryWallet extends Model
{
    protected $fillable = [
        'driver_id',
        'balance',
        'hold_amount',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'hold_amount' => 'decimal:2',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(DeliveryWalletTransaction::class);
    }

    public function credit(float $amount, ?int $orderId = null, ?string $description = null): DeliveryWalletTransaction
    {
        $this->increment('balance', $amount);

        return $this->transactions()->create([
            'order_id' => $orderId,
            'type' => 'credit',
            'amount' => $amount,
            'description' => $description,
        ]);
    }

    public function debit(float $amount, ?int $orderId = null, ?string $description = null): DeliveryWalletTransaction
    {
        $this->decrement('balance', $amount);

        return $this->transactions()->create([
            'order_id' => $orderId,
            'type' => 'debit',
            'amount' => $amount,
            'description' => $description,
        ]);
    }

    public function hold(float $amount, ?int $orderId = null, ?string $description = null): DeliveryWalletTransaction
    {
        $this->increment('hold_amount', $amount);

        return $this->transactions()->create([
            'order_id' => $orderId,
            'type' => 'hold',
            'amount' => $amount,
            'description' => $description,
        ]);
    }

    public function releaseHold(float $amount, ?int $orderId = null, ?string $description = null): DeliveryWalletTransaction
    {
        $this->decrement('hold_amount', min($amount, (float) $this->hold_amount));

        return $this->transactions()->create([
            'order_id' => $orderId,
            'type' => 'release',
            'amount' => $amount,
            'description' => $description,
        ]);
    }

    public function getAvailableBalance(): float
    {
        return (float) $this->balance - (float) $this->hold_amount;
    }
}
