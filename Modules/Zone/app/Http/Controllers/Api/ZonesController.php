<?php

namespace Modules\Zone\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Zone\Models\Zone;

/**
 * API controller to get all zones (used by Vendor, User/Client, Driver APIs)
 */
class ZonesController extends Controller
{
    /**
     * Get all active zones
     * GET /api/v1/vendor/zones | /api/v1/user/zones | /api/v1/driver/zones
     */
    public function index(): JsonResponse
    {
        $zones = Zone::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'is_active', 'delivery_fee', 'minimum_order', 'zone_color', 'points'])
            ->map(function (Zone $zone) {
                $item = [
                    'id' => $zone->id,
                    'name' => $zone->getTranslation('name', app()->getLocale()),
                    'name_ar' => $zone->getTranslation('name', 'ar'),
                    'name_en' => $zone->getTranslation('name', 'en'),
                    'code' => $zone->code,
                    'is_active' => (bool) $zone->is_active,
                    'delivery_fee' => $zone->delivery_fee !== null ? (float) $zone->delivery_fee : null,
                    'minimum_order' => $zone->minimum_order !== null ? (float) $zone->minimum_order : null,
                    'zone_color' => $zone->zone_color,
                    'points' => $zone->points ? $this->normalizePolygonPoints($zone->points) : [],
                ];

                return $item;
            });

        return successResponse($zones->values()->all(), 'Zones retrieved successfully');
    }

    /**
     * Normalize zone points to array of { latitude, longitude }
     */
    private function normalizePolygonPoints(mixed $points): array
    {
        if (is_string($points)) {
            $points = json_decode($points, true);
        }

        if (empty($points)) {
            return [];
        }

        // If it's an object (stdClass), convert to array
        if (is_object($points)) {
            $points = (array) $points;
        }

        if (! is_array($points)) {
            return [];
        }

        // Handle GeoJSON-style or nested coordinates
        $coords = null;
        if (isset($points['coordinates']) && is_array($points['coordinates'])) {
            $coords = $points['coordinates'];
        } elseif (isset($points['points']) && is_array($points['points'])) {
            $coords = $points['points'];
        }

        if ($coords !== null) {
            // Handle GeoJSON Polygon nesting: [[[lng,lat], ...]]
            if (isset($coords[0]) && is_array($coords[0]) && isset($coords[0][0]) && (is_array($coords[0][0]) || is_object($coords[0][0]))) {
                $coords = $coords[0];
            }

            $result = [];
            foreach ($coords as $pt) {
                if (is_array($pt) || is_object($pt)) {
                    $ptArr = (array) $pt;
                    $pLat = $ptArr[1] ?? $ptArr['lat'] ?? $ptArr['latitude'] ?? null;
                    $pLng = $ptArr[0] ?? $ptArr['lng'] ?? $ptArr['long'] ?? $ptArr['longitude'] ?? null;
                    if ($pLat !== null && $pLng !== null) {
                        $result[] = [
                            'latitude' => (float) $pLat,
                            'longitude' => (float) $pLng,
                        ];
                    }
                }
            }
            if (! empty($result)) {
                return $result;
            }
        }

        // Standard array of point objects or arrays
        $result = [];
        foreach ($points as $pt) {
            if (! is_array($pt) && ! is_object($pt)) {
                continue;
            }
            $ptArr = (array) $pt;
            $pLat = $ptArr['lat'] ?? $ptArr['latitude'] ?? $ptArr[1] ?? null;
            $pLng = $ptArr['lng'] ?? $ptArr['long'] ?? $ptArr['longitude'] ?? $ptArr[0] ?? null;

            if ($pLat !== null && $pLng !== null) {
                $result[] = [
                    'latitude' => (float) $pLat,
                    'longitude' => (float) $pLng,
                ];
            }
        }

        return $result;
    }
}
