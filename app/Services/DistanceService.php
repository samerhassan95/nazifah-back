<?php

namespace App\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
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

    /**
     * A "find/sort by real distance" pass (nearest zone, nearest branches list)
     * is one Distance Matrix call per up-to-25 candidates. Above this many
     * candidates it's cheaper and safer to just use aerial distance for that
     * request than to fire several sequential Google calls on a hot endpoint.
     */
    public const MAX_REAL_DISTANCE_CANDIDATES = 60;

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

    /**
     * Distance (km) from one point to many candidate points in as few Google
     * API calls as possible (25 destinations per request), instead of one
     * request per candidate. Falls back to aerial per-candidate if a batch
     * call fails, so the result is always complete.
     *
     * @param  array<int|string, array{lat: float, lon: float}>  $points
     * @return array<int|string, float> distance in km, same keys as $points
     */
    public function distancesTo(float $lat, float $lon, array $points): array
    {
        if ($points === []) {
            return [];
        }

        $mode = AdminSetting::getValue('distance_mode', self::MODE_AERIAL);

        if ($mode === self::MODE_GOOGLE_MAPS) {
            $googleDistances = $this->googleMapsDistancesTo($lat, $lon, $points);
            if ($googleDistances !== null) {
                return $googleDistances;
            }
        }

        $distances = [];
        foreach ($points as $key => $point) {
            $distances[$key] = $this->haversineKm($lat, $lon, (float) $point['lat'], (float) $point['lon']);
        }

        return $distances;
    }

    /**
     * @param  array<int|string, array{lat: float, lon: float}>  $points
     * @return array<int|string, float>|null null means the batch failed entirely —
     *                                        caller falls back to aerial for ALL points.
     */
    private function googleMapsDistancesTo(float $lat, float $lon, array $points): ?array
    {
        $apiKey = AdminSetting::getValue('google_maps_api_key');
        if (! $apiKey) {
            return null;
        }

        $distances = [];

        foreach (array_chunk($points, 25, true) as $chunk) {
            $destinations = implode('|', array_map(
                fn (array $p) => "{$p['lat']},{$p['lon']}",
                $chunk
            ));

            try {
                $response = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/distancematrix/json', [
                    'origins' => "{$lat},{$lon}",
                    'destinations' => $destinations,
                    'mode' => 'driving',
                    'key' => $apiKey,
                ]);

                $elements = $response->json('rows.0.elements');

                if (! $response->successful() || ! is_array($elements) || count($elements) !== count($chunk)) {
                    Log::warning('Google Distance Matrix batch failed, falling back to aerial distance', [
                        'http_status' => $response->status(),
                        'top_status' => $response->json('status'),
                    ]);

                    return null;
                }

                $keys = array_keys($chunk);
                foreach ($elements as $i => $element) {
                    if (($element['status'] ?? null) !== 'OK') {
                        return null;
                    }
                    $distances[$keys[$i]] = ((float) $element['distance']['value']) / 1000;
                }
            } catch (\Throwable $e) {
                Log::warning('Google Distance Matrix batch request failed, falling back to aerial distance', [
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        return $distances;
    }

    /**
     * Re-sorts an already-filtered Eloquent query by real distance and
     * paginates it, instead of the caller's own SQL aerial ORDER BY. Returns
     * null (meaning "use your own SQL aerial query instead") when:
     * - distance_mode isn't google_maps,
     * - the candidate count exceeds MAX_REAL_DISTANCE_CANDIDATES,
     * - or there are no candidates with coordinates at all.
     * Sets a `distance` attribute (km) on each returned model, matching the
     * shape of the SQL aerial query's `AS distance` column, so existing
     * `$model->distance` read sites keep working unchanged.
     */
    public function paginateByRealDistance(
        Builder $baseQuery,
        float $lat,
        float $lon,
        int $perPage,
        int $page,
        string $latColumn = 'latitude',
        string $lonColumn = 'longitude',
    ): ?LengthAwarePaginatorContract {
        if (AdminSetting::getValue('distance_mode', self::MODE_AERIAL) !== self::MODE_GOOGLE_MAPS) {
            return null;
        }

        $total = (clone $baseQuery)->count();
        if ($total === 0 || $total > self::MAX_REAL_DISTANCE_CANDIDATES) {
            return null;
        }

        $candidates = $baseQuery->get();

        $points = [];
        foreach ($candidates as $model) {
            $candidateLat = $model->{$latColumn};
            $candidateLon = $model->{$lonColumn};
            if ($candidateLat === null || $candidateLon === null) {
                continue;
            }
            $points[$model->getKey()] = ['lat' => (float) $candidateLat, 'lon' => (float) $candidateLon];
        }

        if ($points === []) {
            return null;
        }

        $distances = $this->distancesTo($lat, $lon, $points);

        $sorted = $candidates->filter(fn ($model) => isset($distances[$model->getKey()]))
            ->sortBy(fn ($model) => $distances[$model->getKey()])
            ->values();

        foreach ($sorted as $model) {
            $model->setAttribute('distance', $distances[$model->getKey()]);
        }

        $page = max(1, $page);

        return new LengthAwarePaginator(
            $sorted->slice(($page - 1) * $perPage, $perPage)->values(),
            $sorted->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }
}
