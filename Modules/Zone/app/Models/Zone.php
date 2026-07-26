<?php

namespace Modules\Zone\Models;

use App\Traits\Cacheable;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Address\Models\Address;
use Modules\Vendor\Models\Vendor;
use Spatie\Translatable\HasTranslations;

class Zone extends Model
{
    use Cacheable;
    use HasFactory, HasTranslations;
    use HasSoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'description',
        'points',
        'center_latitude',
        'center_longitude',
        'radius',
        'is_active',
        'zone_color',
        'delivery_fee',
        'minimum_order',
    ];

    public array $translatable = ['name', 'description'];

    protected $casts = [
        'points' => 'json',
        'center_latitude' => 'decimal:12',
        'center_longitude' => 'decimal:12',
        'radius' => 'decimal:2',
        'is_active' => 'boolean',
        'delivery_fee' => 'decimal:2',
        'minimum_order' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (Zone $zone) {
            $points = $zone->points;

            if (is_string($points)) {
                $decoded = json_decode($points, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $points = $decoded;
                }
            }

            if (! $points || (! is_array($points) && ! is_object($points))) {
                return;
            }

            $normalizedPoints = static::normalizePointsArray($points);

            if (! empty($normalizedPoints)) {
                $zone->points = $normalizedPoints;
            }
        });

        static::saved(function () {
            self::clearCache();
        });

        static::deleted(function () {
            self::clearCache();
        });
    }

    public static function clearCache(): void
    {
        flushCacheTags(['zones', 'branches']);
    }

    /**
     * Get all addresses in this zone
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    /**
     * Get all vendors in this zone
     */
    public function vendors(): HasMany
    {
        return $this->hasMany(Vendor::class);
    }

    /**
     * Get all branches in this zone
     */
    public function branches(): HasMany
    {
        return $this->hasMany(\Modules\Branch\Models\Branch::class);
    }

    /**
     * Get active branches in this zone
     */
    public function activeBranches()
    {
        return $this->branches()
            ->where('is_active', true)
            ->whereHas('vendor', function ($query) {
                $query->where('is_active', true)->where('is_banned', false);
            });
    }

    /**
     * Check if a coordinate point is within this zone
     */
    public function isPointInZone(float $latitude, float $longitude): bool
    {
        // If using circular zone with radius
        if ($this->radius > 0) {
            // require valid center coordinates to evaluate circular zone
            if ($this->center_latitude === null || $this->center_longitude === null) {
                return false;
            }

            return $this->isPointInRadius($latitude, $longitude);
        }

        // Use polygon coordinates from points
        if ($this->points) {
            return $this->isPointInPolygon($latitude, $longitude);
        }

        return false;
    }

    /**
     * Check if point is within radius from center
     */
    private function isPointInRadius(float $latitude, float $longitude): bool
    {
        if ($this->center_latitude === null || $this->center_longitude === null) {
            return false;
        }

        $distance = $this->calculateDistance(
            (float) $this->center_latitude,
            (float) $this->center_longitude,
            $latitude,
            $longitude
        );

        return $distance <= (float) $this->radius;
    }

    /**
     * Calculate distance between two points using Haversine formula
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // Earth's radius in kilometers

        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLatRad = deg2rad($lat2 - $lat1);
        $deltaLonRad = deg2rad($lon2 - $lon1);

        $a = sin($deltaLatRad / 2) * sin($deltaLatRad / 2) +
             cos($lat1Rad) * cos($lat2Rad) *
             sin($deltaLonRad / 2) * sin($deltaLonRad / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Check if point is within polygon using ray casting algorithm
     */
    private function isPointInPolygon(float $latitude, float $longitude): bool
    {
        $points = $this->points;
        if (is_string($points)) {
            $points = json_decode($points, true);
        }

        if (! $points) {
            return false;
        }

        $coordinates = static::normalizePointsArray($points);

        if (empty($coordinates) || count($coordinates) < 3) {
            return false;
        }

        $x = $longitude;
        $y = $latitude;
        $inside = false;

        // Reset array keys to ensure numeric iteration works if it was an associative array
        $coordinates = array_values($coordinates);
        $count = count($coordinates);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $pi = $coordinates[$i];
            $pj = $coordinates[$j];

            if (! is_array($pi) && ! is_object($pi)) {
                continue;
            }
            if (! is_array($pj) && ! is_object($pj)) {
                continue;
            }

            $piArr = (array) $pi;
            $pjArr = (array) $pj;

            $xi = $piArr['longitude'] ?? $piArr['lng'] ?? $piArr['long'] ?? $piArr[0] ?? null;
            $yi = $piArr['latitude'] ?? $piArr['lat'] ?? $piArr[1] ?? null;
            $xj = $pjArr['longitude'] ?? $pjArr['lng'] ?? $pjArr['long'] ?? $pjArr[0] ?? null;
            $yj = $pjArr['latitude'] ?? $pjArr['lat'] ?? $pjArr[1] ?? null;

            if ($xi === null || $yi === null || $xj === null || $yj === null) {
                continue;
            }

            if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * Find zone for given coordinates
     */
    public static function findZoneByCoordinates(float $latitude, float $longitude): ?Zone
    {
        $zones = static::where('is_active', true)->get();

        foreach ($zones as $zone) {
            if ($zone->isPointInZone($latitude, $longitude)) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * Find nearest active zone to given coordinates. Returns array with zone and distance (km)
     */
    public static function findNearestZoneByCoordinates(float $latitude, float $longitude): ?array
    {
        $zones = static::where('is_active', true)->get();
        $nearest = null;

        foreach ($zones as $zone) {
            $centerLatRaw = $zone->center_latitude;
            $centerLngRaw = $zone->center_longitude;

            // normalize to floats when available; treat null or explicit zero (0,0) as missing
            $centerLat = $centerLatRaw !== null ? (float) $centerLatRaw : null;
            $centerLng = $centerLngRaw !== null ? (float) $centerLngRaw : null;

            $isZeroCenter = ($centerLat === 0.0 && $centerLng === 0.0);

            // if no valid center and points exist, approximate centroid
            if ((is_null($centerLat) || is_null($centerLng) || $isZeroCenter) && $zone->points) {
                $centroid = static::calculatePolygonCentroid(static::normalizePointsArray($zone->points));
                if ($centroid === null) {
                    continue;
                }

                $centerLat = (float) $centroid['lat'];
                $centerLng = (float) $centroid['lng'];
            }

            if ($centerLat === null || $centerLng === null) {
                continue;
            }

            $distance = $zone->calculateDistance($centerLat, $centerLng, $latitude, $longitude);

            if ($nearest === null || $distance < $nearest['distance']) {
                $nearest = [
                    'zone' => $zone,
                    'distance' => $distance,
                    'center' => ['latitude' => (float) $centerLat, 'longitude' => (float) $centerLng],
                ];
            }
        }

        return $nearest;
    }

    /**
     * Calculate centroid of polygon coordinates (simple average)
     */
    private static function calculatePolygonCentroid(array $coordinates): ?array
    {
        $sumLat = 0.0;
        $sumLng = 0.0;
        $count = 0;

        foreach ($coordinates as $pt) {
            if (! is_array($pt) && ! is_object($pt)) {
                continue;
            }

            $ptArr = (array) $pt;
            $lat = $ptArr['latitude'] ?? $ptArr['lat'] ?? $ptArr[1] ?? null;
            $lng = $ptArr['longitude'] ?? $ptArr['lng'] ?? $ptArr['long'] ?? $ptArr[0] ?? null;

            if ($lat !== null && $lng !== null) {
                $sumLat += (float) $lat;
                $sumLng += (float) $lng;
                $count++;
            }
        }

        if ($count === 0) {
            return null;
        }

        return ['lat' => $sumLat / $count, 'lng' => $sumLng / $count];
    }

    /**
     * Normalize points to array of objects with latitude/longitude.
     */
    private static function normalizePointsArray(mixed $points): array
    {
        if (is_string($points)) {
            $points = json_decode($points, true);
        } elseif (is_object($points)) {
            $points = (array) $points;
        }

        if (! is_array($points)) {
            return [];
        }

        if (isset($points['coordinates']) && is_array($points['coordinates'])) {
            $points = $points['coordinates'];
            if (isset($points[0]) && is_array($points[0]) && isset($points[0][0]) && is_array($points[0][0])) {
                $points = $points[0];
            }
        } elseif (isset($points['points']) && is_array($points['points'])) {
            $points = $points['points'];
        }

        $normalized = [];
        foreach ($points as $point) {
            if (! is_array($point) && ! is_object($point)) {
                continue;
            }

            $pointArr = (array) $point;
            $latitude = $pointArr['latitude'] ?? $pointArr['lat'] ?? $pointArr[1] ?? null;
            $longitude = $pointArr['longitude'] ?? $pointArr['lng'] ?? $pointArr['long'] ?? $pointArr[0] ?? null;

            if ($latitude === null || $longitude === null) {
                continue;
            }

            $normalized[] = [
                'latitude' => (float) $latitude,
                'longitude' => (float) $longitude,
            ];
        }

        return $normalized;
    }
}
