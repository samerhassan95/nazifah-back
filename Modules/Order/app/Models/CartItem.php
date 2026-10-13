<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Piece\Models\Piece;

class CartItem extends Model
{
    protected $fillable = [
        'cart_id',
        'piece_id',
        'service_ids',
        'additional_service_ids',
        'quantity',
        'note',
        'image',
    ];

    protected $casts = [
        'service_ids' => 'array',
        'additional_service_ids' => 'array',
        'quantity' => 'integer',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function piece(): BelongsTo
    {
        return $this->belongsTo(Piece::class);
    }
}
