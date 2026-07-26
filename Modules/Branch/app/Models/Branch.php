<?php

namespace Modules\Branch\Models;

use App\Traits\Cacheable;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Modules\Driver\Models\Driver;
use Modules\Order\Models\Order;
use Modules\Piece\Models\Piece;
use Modules\Service\Models\Service;
use Modules\Vendor\Models\Vendor;
use Modules\Vendor\Models\VendorEmployee;
use Modules\Zone\Models\Zone;
use Spatie\Translatable\HasTranslations;

class Branch extends Model
{
    use Cacheable;
    use HasSoftDeletes;
    use HasTranslations;

    protected $fillable = [
        'vendor_id',
        'zone_id',
        'name',
        'phone_number',
        'land_phone',
        'location',
        'national_address',
        'store_front',
        'logo',
        'description',
        'latitude',
        'longitude',
        'is_active',
        'rating',
        'rate_count',
        'home_pickup',
        'self_dropoff',
        'home_delivery',
        'self_pickup',
    ];

    protected $casts = [
        'delivery_and_pickup' => 'boolean',
        'is_active' => 'boolean',
        'latitude' => 'decimal:12',
        'longitude' => 'decimal:12',
        'rating' => 'decimal:2',
        'rate_count' => 'integer',
        'home_pickup' => 'boolean',
        'self_dropoff' => 'boolean',
        'home_delivery' => 'boolean',
        'self_pickup' => 'boolean',
    ];

    // Add is_active to mass assignable
    protected $attributes = [
        'is_active' => true,
        'home_pickup' => false,
        'self_dropoff' => false,
        'home_delivery' => false,
        'self_pickup' => false,
    ];

    public array $translatable = ['name', 'location', 'description'];

    /**
     * Boot method to auto-assign zone based on coordinates
     */
    protected static function booted()
    {
        static::saving(function ($branch) {
            // Auto-assign zone based on coordinates if changed or not set
            if ($branch->latitude && $branch->longitude) {
                if (! $branch->zone_id || ($branch->isDirty('latitude') || $branch->isDirty('longitude'))) {
                    // Only auto-assign if zone_id was not explicitly updated
                    if (! $branch->isDirty('zone_id')) {
                        $zone = Zone::findZoneByCoordinates($branch->latitude, $branch->longitude);
                        $branch->zone_id = $zone ? $zone->id : null;
                    }
                }
            }
        });

        static::saved(fn ($branch) => self::clearCache($branch));
        static::deleted(fn ($branch) => self::clearCache($branch));
    }

    public static function clearCache(Branch $branch): void
    {
        flushCacheTags(['branches', 'vendors', 'zones']);
        flushCacheTags("branch_{$branch->id}");
    }

    /**
     * Get the vendor that owns the branch.
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Get the zone this branch belongs to.
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /**
     * Working-hour shifts grouped by day (multiple shifts per day supported).
     */
    public function workingHourShifts(): HasMany
    {
        // Order Sat→Fri. A CASE expression is portable across MySQL/SQLite/Postgres,
        // unlike MySQL-only FIELD() (which breaks on the SQLite test database).
        return $this->hasMany(BranchWorkingHourShift::class)
            ->orderByRaw('CASE day_of_week'
                ." WHEN 'saturday' THEN 0 WHEN 'sunday' THEN 1 WHEN 'monday' THEN 2"
                ." WHEN 'tuesday' THEN 3 WHEN 'wednesday' THEN 4 WHEN 'thursday' THEN 5"
                ." WHEN 'friday' THEN 6 ELSE 7 END")
            ->orderBy('sort_order');
    }

    /**
     * Per-day working hours for API responses.
     */
    public function getApiWorkingHours(): array
    {
        return app(\Modules\Branch\Services\BranchWorkingHoursService::class)->toApiFormat($this);
    }

    /**
     * Get employees assigned to this branch
     */
    public function employees(): HasMany
    {
        return $this->hasMany(VendorEmployee::class);
    }

    /**
     * Get orders for this branch.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get services available at this branch.
     * Branch can have specific services from the vendor's service catalog.
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'branch_service', 'branch_id', 'service_id')
            ->withPivot('icon_id', 'name', 'description', 'is_active')
            ->withTimestamps();
    }

    /**
     * Get active services at this branch
     */
    public function activeServices(): BelongsToMany
    {
        return \App\Support\CatalogActiveFilter::activeServicesOnBranch($this->services());
    }

    /**
     * Get pieces available at this branch.
     * Branch can have specific pieces from the vendor's piece catalog.
     */
    public function pieces(): BelongsToMany
    {
        return $this->belongsToMany(Piece::class, 'branch_piece', 'branch_id', 'piece_id')
            ->withPivot('name', 'is_active')
            ->withTimestamps();
    }

    /**
     * Get active pieces at this branch
     */
    public function activePieces(): BelongsToMany
    {
        return \App\Support\CatalogActiveFilter::activePiecesOnBranch($this->pieces());
    }

    /**
     * Branch-specific service additions (custom name + price per branch).
     */
    public function serviceAdditions(): BelongsToMany
    {
        return $this->belongsToMany(\Modules\Service\Models\ServiceAddition::class, 'branch_service_addition', 'branch_id', 'service_addition_id')
            ->withPivot('name', 'price', 'icon_id', 'is_active')
            ->withTimestamps();
    }

    /**
     * Active service additions offered at this branch.
     */
    public function activeServiceAdditions(): BelongsToMany
    {
        return \App\Support\CatalogActiveFilter::activeServiceAdditionsOnBranch($this->serviceAdditions());
    }

    /**
     * Get delivery drivers assigned to this branch.
     */
    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    /**
     * Get active delivery drivers at this branch
     */
    public function activeDrivers(): HasMany
    {
        return $this->drivers();
    }

    /**
     * Get available drivers (online and available)
     */
    public function availableDrivers(): HasMany
    {
        return $this->activeDrivers()
            ->where('is_available', true);
    }

    /**
     * Check if branch location is within a specific zone
     */
    public function isInZone(Zone $zone): bool
    {
        if (! $this->latitude || ! $this->longitude) {
            return false;
        }

        return $zone->isPointInZone((float) $this->latitude, (float) $this->longitude);
    }

    /**
     * Scope: Get branches within a zone
     */
    public function scopeInZone($query, int $zoneId)
    {
        return $query->where('zone_id', $zoneId);
    }

    /**
     * Scope: Get active branches
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Get branches by vendor
     */
    public function scopeByVendor($query, int $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    /**
     * Get branches available in a zone (for clients)
     */
    public static function getAvailableInZone(int $zoneId)
    {
        return static::where('zone_id', $zoneId)
            ->where('is_active', true)
            ->with(['vendor' => function ($query) {
                $query->where('is_active', true)->where('is_banned', false);
            }])
            ->whereHas('vendor', function ($query) {
                $query->where('is_active', true)->where('is_banned', false);
            })
            ->get();
    }

    /**
     * Get branches in a zone that offer a specific service
     */
    public static function getAvailableInZoneWithService(int $zoneId, int $serviceId)
    {
        return static::where('zone_id', $zoneId)
            ->where('is_active', true)
            ->whereHas('vendor', function ($query) {
                $query->where('is_active', true)->where('is_banned', false);
            })
            ->whereHas('services', function ($query) use ($serviceId) {
                $query->where('services.id', $serviceId);
            })
            ->with(['vendor', 'zone', 'services' => function ($query) use ($serviceId) {
                $query->where('services.id', $serviceId);
            }])
            ->get();
    }

    /**
     * Get branches in a zone that offer specific services
     */
    public static function getAvailableInZoneWithServices(int $zoneId, array $serviceIds)
    {
        return static::where('zone_id', $zoneId)
            ->where('is_active', true)
            ->whereHas('vendor', function ($query) {
                $query->where('is_active', true)->where('is_banned', false);
            })
            ->whereHas('services', function ($query) use ($serviceIds) {
                $query->whereIn('services.id', $serviceIds);
            })
            ->with(['vendor', 'zone', 'services' => function ($query) use ($serviceIds) {
                $query->whereIn('services.id', $serviceIds);
            }])
            ->get();
    }

    /**
     * Scope: Get branches that have a specific service
     */
    public function scopeWithService($query, int $serviceId)
    {
        return $query->whereHas('services', function ($q) use ($serviceId) {
            $q->where('services.id', $serviceId);
        });
    }

    /**
     * Scope: Get branches that have any of the specified services
     */
    public function scopeWithServices($query, array $serviceIds)
    {
        return $query->whereHas('services', function ($q) use ($serviceIds) {
            $q->whereIn('services.id', $serviceIds);
        });
    }

    /**
     * Scope: Get branches with available drivers
     */
    public function scopeWithAvailableDrivers($query)
    {
        return $query->whereHas('drivers', function ($q) {
            $q->where('is_available', true);
        });
    }

    /**
     * Check if branch has a specific service
     */
    public function hasService(int $serviceId): bool
    {
        return $this->services()
            ->where('services.id', $serviceId)
            ->exists();
    }

    /**
     * Check if branch has a specific piece
     */
    public function hasPiece(int $pieceId): bool
    {
        return $this->pieces()
            ->where('pieces.id', $pieceId)
            ->exists();
    }

    /**
     * Services have no standalone price at a branch — use service_piece per piece.
     */
    public function getServicePrice(int $serviceId): ?float
    {
        return null;
    }

    /**
     * Get the price for a piece in a specific service at this branch.
     * Branch-aware lookup against service_piece:
     *   1. service_piece WHERE branch_id = $this->id
     *   2. service_piece WHERE branch_id IS NULL (vendor-level)
     *   3. null when no service_piece row exists (caller decides fallback)
     */
    public function getPiecePriceForService(int $serviceId, int $pieceId): ?float
    {
        $row = \Illuminate\Support\Facades\DB::table('service_piece')
            ->where('service_id', $serviceId)
            ->where('piece_id', $pieceId)
            ->where('branch_id', $this->id)
            ->first();
        if ($row && $row->price !== null) {
            return (float) $row->price;
        }

        $row = \Illuminate\Support\Facades\DB::table('service_piece')
            ->where('service_id', $serviceId)
            ->where('piece_id', $pieceId)
            ->whereNull('branch_id')
            ->first();
        if ($row && $row->price !== null) {
            return (float) $row->price;
        }

        return null;
    }

    /**
     * Get the price for an additional service for a specific piece at this branch
     */
    public function getAdditionalServicePrice(int $serviceAdditionId, int $pieceId): ?float
    {
        $serviceAddition = \Modules\Service\Models\ServiceAddition::find($serviceAdditionId);

        if ($serviceAddition) {
            return $serviceAddition->getPriceForPieceAtBranch($pieceId, $this->id);
        }

        return null;
    }

    /**
     * Sync services from vendor to branch
     * Optionally specify which services to sync, otherwise syncs all vendor services
     */
    public function syncServicesFromVendor(?array $serviceIds = null): void
    {
        $vendorServices = $this->vendor->services();

        if ($serviceIds) {
            $vendorServices = $vendorServices->whereIn('services.id', $serviceIds);
        }

        $servicesToSync = [];
        foreach ($vendorServices->get() as $service) {
            $servicesToSync[$service->id] = [
                'price' => null, // Use vendor price by default
            ];
        }

        $this->services()->sync($servicesToSync);

        // cache invalidation: branch services sync
        self::clearCache($this);
    }

    /**
     * Sync pieces from vendor to branch
     * Optionally specify which pieces to sync, otherwise syncs all vendor pieces
     */
    public function syncPiecesFromVendor(?array $pieceIds = null): void
    {
        $vendorPieces = $this->vendor->pieces();

        if ($pieceIds) {
            $vendorPieces = $vendorPieces->whereIn('id', $pieceIds);
        }

        $piecesToSync = [];
        foreach ($vendorPieces->get() as $piece) {
            $piecesToSync[$piece->id] = [];
        }

        $this->pieces()->sync($piecesToSync);

        // cache invalidation: branch pieces sync
        self::clearCache($this);
    }

    /**
     * Localized display address for API responses.
     */
    public function getLocalizedAddress(?string $locale = null): ?string
    {
        $locale = $locale ?? app()->getLocale();

        return $this->getTranslation('location', $locale)
            ?? $this->national_address
            ?? $this->getTranslation('name', $locale);
    }

    /**
     * Standard branch location payload for order APIs.
     */
    public function getApiLocation(?string $locale = null): array
    {
        return [
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'address' => $this->getLocalizedAddress($locale),
            'national_address' => $this->national_address,
        ];
    }

    /**
     * Branch block with nested location (vendor/user order detail shape).
     */
    public function toApiOrderBranch(?string $locale = null, array $extra = []): array
    {
        $locale = $locale ?? app()->getLocale();

        return array_merge([
            'id' => $this->id,
            'name' => $this->getTranslation('name', $locale) ?? $this->name,
            'location' => $this->getApiLocation($locale),
            'working_hours' => $this->getApiWorkingHours(),
        ], $extra);
    }

    /**
     * Flat branch block with top-level coordinates (client order list shape).
     */
    public function toApiOrderBranchFlat(?string $locale = null, array $extra = []): array
    {
        $locale = $locale ?? app()->getLocale();
        $location = $this->getApiLocation($locale);

        return array_merge([
            'id' => $this->id,
            'name' => $this->getTranslation('name', $locale) ?? $this->name,
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
            'location' => $location['address'],
            'national_address' => $location['national_address'],
            'working_hours' => $this->getApiWorkingHours(),
        ], $extra);
    }

    /**
     * Branch map point for driver tracking APIs.
     */
    public function toApiMapPoint(?string $locale = null): array
    {
        $locale = $locale ?? app()->getLocale();

        return array_merge(
            ['type' => 'branch', 'name' => $this->getTranslation('name', $locale) ?? $this->name],
            $this->getApiLocation($locale)
        );
    }

    /**
     * Assign a driver to this branch
     */
    public function assignDriver(int $driverId): void
    {
        $driver = Driver::find($driverId);
        if ($driver) {
            $driver->update([
                'branch_id' => $this->id,
            ]);

            // cache invalidation: branch driver assign
            self::clearCache($this);
        }
    }

    /**
     * Remove a driver from this branch
     */
    public function removeDriver(int $driverId): void
    {
        $driver = $this->drivers()->where('id', $driverId)->first();
        if ($driver) {
            $driver->update(['branch_id' => null]);

            // cache invalidation: branch driver remove
            self::clearCache($this);
        }
    }
}
