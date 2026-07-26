<?php

namespace Modules\Driver\Repositories;

use App\Cache\CacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Driver\Cache\DriverCacheKey;
use Modules\Driver\Interfaces\DriverRepositoryInterface;
use Modules\Driver\Models\Driver;

class DriverRepository implements DriverRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $key = DriverCacheKey::filteredCollection($filters);

        return CacheManager::remember(
            key: $key,
            ttl: CacheManager::TTL_SHORT,
            callback: function () use ($filters) {
                $query = Driver::query();

                if (isset($filters['search'])) {
                    $search = $filters['search'];
                    $query->where(function ($q) use ($search) {
                        $q->whereRaw("JSON_EXTRACT(full_name, '$$.ar') LIKE ?", ["%{$search}%"])
                            ->orWhereRaw("JSON_EXTRACT(full_name, '$$.en') LIKE ?", ["%{$search}%"])
                            ->orWhere('phone', 'like', "%{$search}%");
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
            tags: ['drivers']
        );
    }

    public function find(int $id): ?Driver
    {
        return CacheManager::remember(
            key: DriverCacheKey::withRelations($id),
            ttl: CacheManager::TTL_SHORT,
            callback: fn () => Driver::with(['branch', 'vendor'])->find($id),
            tags: ['drivers', "driver:{$id}"]
        );
    }

    public function create(array $data): Driver
    {
        $driver = Driver::create($data);
        $this->clearCollectionCache();

        return $driver;
    }

    public function update(Driver $driver, array $data): bool
    {
        $result = $driver->update($data);
        $this->clearCache($driver->id);

        return $result;
    }

    public function delete(Driver $driver): bool
    {
        $id = $driver->id;
        $result = $driver->delete();
        $this->clearCache($id);

        return $result;
    }

    // ─── Private helpers ─────────────────────────────────────────────

    private function clearCache(int $id): void
    {
        CacheManager::forgetKeys([
            DriverCacheKey::single($id),
            DriverCacheKey::withRelations($id),
            DriverCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['drivers', "driver:{$id}"]);
    }

    private function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            DriverCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['drivers']);
    }
}
