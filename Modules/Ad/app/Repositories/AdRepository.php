<?php

namespace Modules\Ad\Repositories;

use App\Cache\CacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Ad\Cache\AdCacheKey;
use Modules\Ad\Interfaces\AdRepositoryInterface;
use Modules\Ad\Models\Ad;

class AdRepository implements AdRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $key = AdCacheKey::filteredCollection($filters);

        return CacheManager::remember(
            key: $key,
            ttl: CacheManager::TTL_SHORT,
            callback: function () use ($filters) {
                $query = Ad::query();

                if (isset($filters['search'])) {
                    $search = $filters['search'];
                    $query->where(function ($q) use ($search) {
                        $q->where('title', 'like', "%{$search}%");
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
            tags: ['ads']
        );
    }

    public function find(int $id): ?Ad
    {
        return CacheManager::remember(
            key: AdCacheKey::single($id),
            ttl: CacheManager::TTL_MEDIUM,
            callback: fn () => Ad::find($id),
            tags: ['ads', "ad:{$id}"]
        );
    }

    public function create(array $data): Ad
    {
        $ad = Ad::create($data);
        $this->clearCollectionCache();

        return $ad;
    }

    public function update(Ad $ad, array $data): bool
    {
        $result = $ad->update($data);
        $this->clearCache($ad->id);

        return $result;
    }

    public function delete(Ad $ad): bool
    {
        $id = $ad->id;
        $result = $ad->delete();
        $this->clearCache($id);

        return $result;
    }

    public function getStatistics(): array
    {
        return CacheManager::remember(
            key: AdCacheKey::statistics(),
            ttl: CacheManager::TTL_SHORT,
            callback: function () {
                return [
                    'total_ads' => Ad::count(),
                    'active_ads' => Ad::where('is_active', true)->count(),
                    'inactive_ads' => Ad::where('is_active', false)->count(),
                ];
            },
            tags: ['ads']
        );
    }

    // ─── Private helpers ─────────────────────────────────────────────

    private function clearCache(int $id): void
    {
        CacheManager::forgetKeys([
            AdCacheKey::single($id),
            AdCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['ads', "ad:{$id}"]);
    }

    private function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            AdCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['ads']);
    }
}
