<?php

namespace Modules\Address\Models;

use App\Traits\Cacheable;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Client\Models\Client;
use Modules\Zone\Models\Zone;

class Address extends Model
{
    use Cacheable;
    use HasFactory;
    use HasSoftDeletes;

    protected $fillable = [
        'client_id',
        'zone_id',
        'title',
        'address_label',
        'address_text',
        'national_address',
        'street_name',
        'building_number',
        'street_number',
        'city',
        'district',
        'postal_code',
        'floor',
        'floor_number',
        'apartment',
        'latitude',
        'longitude',
        'notes',
        'is_default',
    ];

    protected $casts = [
        'latitude' => 'decimal:12',
        'longitude' => 'decimal:12',
        'is_default' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /**
     * API payload for the two separate DB columns (`floor` ≠ `floor_number`).
     *
     * @return array{floor: mixed, floor_number: mixed}
     */
    public function getApiFloorAttributes(): array
    {
        return [
            'floor' => $this->floor,
            'floor_number' => $this->floor_number,
        ];
    }

    /**
     * Standard client default address payload for order APIs.
     *
     * @return array<string, mixed>
     */
    public function toApiClientAddressArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'address_text' => $this->address_text ?? $this->street_name,
            'national_address' => $this->national_address,
            'building_number' => $this->building_number,
            'street_number' => $this->street_number,
            ...$this->getApiFloorAttributes(),
            'apartment' => $this->apartment,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'notes' => $this->notes,
            'is_default' => (bool) $this->is_default,
        ];
    }

    /**
     * Automatically assign zone based on coordinates when creating/updating address
     */
    protected static function booted()
    {
        static::saving(function ($address) {
            if ($address->latitude && $address->longitude && ! $address->zone_id) {
                $zone = Zone::findZoneByCoordinates($address->latitude, $address->longitude);
                if ($zone) {
                    $address->zone_id = $zone->id;
                } else {
                    $address->zone_id = null;
                }
            }
        });

        static::saved(function ($address) {
            flushCacheTags(["user_{$address->client_id}_addresses", 'addresses']);
        });

        static::deleted(function ($address) {
            flushCacheTags(["user_{$address->client_id}_addresses", 'addresses']);
        });
    }
}
