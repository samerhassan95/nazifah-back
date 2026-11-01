<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Admin\Models\AdminSetting;

/**
 * Central point-to-point distance calculator. Admin-configurable (AdminSetting
 * key 'distance_mode') between two modes:
 * - aerial: straight-line (Haversine) distance.
 * - google_maps: real driving distance via Google's Distance Matrix API,
 *   using the AdminSetting key 'google_maps_api_key'. Falls back to aerial
 *   on any failure (missing/invalid key, API error, network error) so
 *   delivery-fee calculation never breaks because of an external API.
 */
class DistanceService
{
    public const MODE_AERIAL = 'aerial';

    public const MODE_GOOGLE_MAPS = 'google_maps';

    public function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $mode = AdminSetting::getValue('distance_mode', self::MODE_AERIAL);

        if ($mode === self::MODE_GOOGLE_MAPS) {
            $googleDistance = $this->googleMapsDistanceKm($lat1, $lon1, $lat2, $lon2);
            if ($googleDistance !== null) {
                return $googleDistance;
            }
        }

        return $this->haversineKm($lat1, $lon1, $lat2, $lon2);
    }

    public function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    private function googleMapsDistanceKm(float $lat1, float $lon1, float $lat2, float $lon2): ?float
    {
        $apiKey = AdminSetting::getValue('google_maps_api_key');
        if (! $apiKey) {
            return null;
        }

        try {
            $response = Http::timeout(5)->get('https://maps.googleapis.com/maps/api/distancematrix/json', [
                'origins' => "{$lat1},{$lon1}",
                'destinations' => "{$lat2},{$lon2}",
                'mode' => 'driving',
                'key' => $apiKey,
            ]);

            $element = $response->json('rows.0.elements.0');
            $elementStatus = $element['status'] ?? null;

            if (! $response->successful() || $elementStatus !== 'OK') {
                Log::warning('Google Distance Matrix failed, falling back to aerial distance', [
                    'http_status' => $response->status(),
                    'element_status' => $elementStatus,
                    'top_status' => $response->json('status'),
                ]);

                return null;
            }

            return ((float) $element['distance']['value']) / 1000;
        } catch (\Throwable $e) {
            Log::warning('Google Distance Matrix request failed, falling back to aerial distance', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
