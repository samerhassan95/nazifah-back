<?php

namespace Modules\Zone\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Zone\Interfaces\ZoneRepositoryInterface;
use Modules\Zone\Models\Zone;

class ZoneService
{
    public function __construct(
        private ZoneRepositoryInterface $zoneRepository
    ) {}

    public function getAllZones(array $filters = []): LengthAwarePaginator
    {
        return $this->zoneRepository->all($filters);
    }

    public function getZoneById(int $id): ?Zone
    {
        return $this->zoneRepository->find($id);
    }

    public function createZone(array $data): Zone
    {
        return $this->zoneRepository->create($data);
    }

    public function updateZone(int $id, array $data): ?Zone
    {
        $zone = $this->zoneRepository->find($id);

        if (! $zone) {
            return null;
        }

        $this->zoneRepository->update($zone, $data);

        return $zone->fresh();
    }

    public function deleteZone(int $id): bool
    {
        $zone = $this->zoneRepository->find($id);

        if (! $zone) {
            return false;
        }

        return $this->zoneRepository->delete($zone);
    }

    public function findByCoordinates(float $latitude, float $longitude): ?Zone
    {
        return Zone::findZoneByCoordinates($latitude, $longitude);
    }

    /**
     * Find the nearest active zone to given coordinates
     */
    public function findNearestZone(float $latitude, float $longitude): ?array
    {
        $res = Zone::findNearestZoneByCoordinates($latitude, $longitude);

        if (! $res) {
            return null;
        }

        return [
            'zone' => $res['zone'],
            'latitude' => $res['center']['latitude'] ?? ($res['center']['lat'] ?? null),
            'longitude' => $res['center']['longitude'] ?? ($res['center']['lng'] ?? null),
            'distance' => round($res['distance'], 2),
        ];
    }

    /**
     * Calculate centroid of polygon coordinates
     */
    private function calculatePolygonCentroid(Zone $zone): ?array
    {
        $coordinates = $zone->points;

        if (! $coordinates || ! is_array($coordinates) || count($coordinates) < 3) {
            return null;
        }

        $latSum = 0;
        $lngSum = 0;
        $count = count($coordinates);

        foreach ($coordinates as $coord) {
            $latSum += $coord['lat'] ?? $coord['latitude'] ?? 0;
            $lngSum += $coord['lng'] ?? $coord['long'] ?? $coord['longitude'] ?? 0;
        }

        return [
            'latitude' => $latSum / $count,
            'longitude' => $lngSum / $count,
        ];
    }

    /**
     * Calculate distance between two coordinates using Haversine formula
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

    public function validateLocation(float $latitude, float $longitude): array
    {
        $zone = $this->findByCoordinates($latitude, $longitude);

        if (! $zone) {
            $nearest = $this->findNearestZone($latitude, $longitude);

            if ($nearest) {
                return [
                    'valid' => false,
                    'message' => __('zones.location_not_in_service_area_with_nearest', [
                        'distance' => number_format($nearest['distance'], 2),
                        'latitude' => number_format($nearest['latitude'], 6),
                        'longitude' => number_format($nearest['longitude'], 6),
                    ]),
                    'zone' => null,
                    'nearest_zone' => [
                        'zone_id' => $nearest['zone']->id,
                        'zone_name' => $nearest['zone']->name,
                        'latitude' => $nearest['latitude'],
                        'longitude' => $nearest['longitude'],
                        'distance_km' => $nearest['distance'],
                    ],
                ];
            }

            return [
                'valid' => false,
                'message' => __('zones.location_not_in_service_area'),
                'zone' => null,
                'nearest_zone' => null,
            ];
        }

        return [
            'valid' => true,
            'message' => __('zones.location_valid'),
            'zone' => $zone,
        ];
    }

    public function getAllPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->zoneRepository->all($filters, $perPage);
    }

    public function find(int $id): ?Zone
    {
        return $this->zoneRepository->find($id);
    }

    public function create(array $data): Zone
    {
        return $this->zoneRepository->create($data);
    }

    public function update(int $id, array $data): ?Zone
    {
        return $this->updateZone($id, $data);
    }

    public function delete(int $id): bool
    {
        return $this->deleteZone($id);
    }

    public function toggleStatus(int $id): ?Zone
    {
        $zone = $this->zoneRepository->find($id);

        if (! $zone) {
            return null;
        }

        return $this->zoneRepository->toggleStatus($zone);
    }

    public function getStatistics(): array
    {
        return $this->zoneRepository->getStatistics();
    }
}
