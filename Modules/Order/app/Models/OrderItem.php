<?php

namespace Modules\Order\Models;

use App\Traits\Cacheable;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Piece\Models\Piece;
use Modules\Service\Models\Service;

class OrderItem extends Model
{
    use Cacheable;
    use HasFactory;
    use HasSoftDeletes;

    protected $fillable = [
        'order_id',
        'piece_id',
        'service_id',
        'piece_price',
        'service_price',
        'quantity',
        'unit_price',
        'total_price',
        'notes',
        'images',
        'vendor_status',
        'vendor_notes',
        'original_quantity',
        'original_unit_price',
        'original_total_price',
        'modified_quantity',
        'modified_unit_price',
        'modified_total_price',
        'client_approved_modification',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'piece_price' => 'decimal:2',
        'service_price' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'original_quantity' => 'integer',
        'original_unit_price' => 'decimal:2',
        'original_total_price' => 'decimal:2',
        'modified_quantity' => 'integer',
        'modified_unit_price' => 'decimal:2',
        'modified_total_price' => 'decimal:2',
        'client_approved_modification' => 'boolean',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function piece(): BelongsTo
    {
        return $this->belongsTo(Piece::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function additions(): HasMany
    {
        return $this->hasMany(OrderItemAddition::class);
    }

    /**
     * Get additional services pivot records
     */
    public function additionalServicesPivot(): HasMany
    {
        return $this->hasMany(OrderItemAdditionalService::class);
    }

    /**
     * Get additional services through the pivot table
     */
    public function additionalServicesRelation(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            \Modules\Service\Models\ServiceAddition::class,
            'order_item_additional_services',
            'order_item_id',
            'service_addition_id'
        )->withPivot('price', 'quantity')->withTimestamps();
    }

    /**
     * Get additional services (alias for additionalServicesPivot for backward compatibility)
     */
    public function additionalServices(): HasMany
    {
        return $this->hasMany(OrderItemAdditionalService::class);
    }
}
