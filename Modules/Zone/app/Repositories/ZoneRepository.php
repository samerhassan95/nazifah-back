<?php

namespace Modules\Zone\Repositories;

use App\Cache\CacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Zone\Cache\ZoneCacheKey;
use Modules\Zone\Interfaces\ZoneRepositoryInterface;
use Modules\Zone\Models\Zone;

class ZoneRepository implements ZoneRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $key = ZoneCacheKey::filteredCollection($filters);

        return CacheManager::remember(
            key: $key,
            ttl: CacheManager::TTL_LONG,
            callback: function () use ($filters) {
                $query = Zone::query();

                if (isset($filters['search'])) {
                    $search = $filters['search'];
                    $query->where(function ($q) use ($search) {
                        $q->whereRaw("JSON_EXTRACT(name, '$$.ar') LIKE ?", ["%{$search}%"])
                            ->orWhereRaw("JSON_EXTRACT(name, '$$.en') LIKE ?", ["%{$search}%"]);
                    });
                }

                if (isset($filters['is_active'])) {
                    $query->where('is_active', $filters['is_active']);
                }

                $sortBy = $filters['sort_by'] ?? 'created_at';
                $sortOrder = $filters['sort_order'] ?? 'desc';
                $query->orderBy($sortBy, $sortOrder);

                return $query->paginate($filters['per_page'] ?? 15);
            },
            tags: ['zones']
        );
    }

    public function find(int $id): ?Zone
    {
        return CacheManager::remember(
            key: ZoneCacheKey::withRelations($id),
            ttl: CacheManager::TTL_LONG,
            callback: fn () => Zone::with(['branches'])->find($id),
            tags: ['zones', "zone:{$id}"]
        );
    }

    public function create(array $data): Zone
    {
        if (isset($data['points'])) {
            $data['points'] = $this->normalizePoints($data['points']);
        }

        $zone = Zone::create($data);
        $this->clearCollectionCache();

        return $zone;
    }

    public function update(Zone $zone, array $data): bool
    {
        if (isset($data['points'])) {
            $data['points'] = $this->normalizePoints($data['points']);
        }

        $result = $zone->update($data);
        $this->clearCache($zone->id);

        return $result;
    }

    public function delete(Zone $zone): bool
    {
        $id = $zone->id;
        $result = $zone->delete();
        $this->clearCache($id);

        return $result;
    }

    // ─── Private helpers ─────────────────────────────────────────────

    private function clearCache(int $id): void
    {
        CacheManager::forgetKeys([
            ZoneCacheKey::single($id),
            ZoneCacheKey::withRelations($id),
            ZoneCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['zones', "zone:{$id}"]);
    }

    private function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            ZoneCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['zones']);
    }

    private function normalizePoints(mixed $points): array
    {
        if (is_string($points)) {
            $points = json_decode($points, true);
        } elseif (is_object($points)) {
            $points = (array) $points;
        }

        if (! is_array($points)) {
            return [];
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
