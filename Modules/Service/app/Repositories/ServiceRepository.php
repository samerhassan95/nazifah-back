<?php

namespace Modules\Service\Repositories;

use App\Cache\CacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Service\Cache\ServiceCacheKey;
use Modules\Service\Interfaces\ServiceRepositoryInterface;
use Modules\Service\Models\Service;

class ServiceRepository implements ServiceRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $key = ServiceCacheKey::filteredCollection($filters);

        return CacheManager::remember(
            key: $key,
            ttl: CacheManager::TTL_LONG,
            callback: function () use ($filters) {
                $query = Service::with(['category']);

                if (isset($filters['search'])) {
                    $search = $filters['search'];
                    $query->where(function ($q) use ($search) {
                        $q->whereRaw("JSON_EXTRACT(service_name, '$$.ar') LIKE ?", ["%{$search}%"])
                            ->orWhereRaw("JSON_EXTRACT(service_name, '$$.en') LIKE ?", ["%{$search}%"]);
                    });
                }

                if (isset($filters['is_active'])) {
                    $query->where('is_active', $filters['is_active']);
                }

                if (isset($filters['category_id'])) {
                    $query->where('category_id', $filters['category_id']);
                }

                $sortBy = $filters['sort_by'] ?? 'created_at';
                $sortOrder = $filters['sort_order'] ?? 'desc';
                $query->orderBy($sortBy, $sortOrder);

                return $query->paginate($filters['per_page'] ?? 15);
            },
            tags: ['services']
        );
    }

    public function find(int $id): ?Service
    {
        return CacheManager::remember(
            key: ServiceCacheKey::withRelations($id),
            ttl: CacheManager::TTL_LONG,
            callback: fn () => Service::with(['category'])->find($id),
            tags: ['services', "service:{$id}"]
        );
    }

    public function create(array $data): Service
    {
        $service = Service::create($data);
        $this->clearCollectionCache();

        return $service;
    }

    public function update(Service $service, array $data): bool
    {
        $result = $service->update($data);
        $this->clearCache($service->id);

        return $result;
    }

    public function delete(Service $service): bool
    {
        $id = $service->id;
        $result = $service->delete();
        $this->clearCache($id);

        return $result;
    }

    // ─── Private helpers ─────────────────────────────────────────────

    private function clearCache(int $id): void
    {
        CacheManager::forgetKeys([
            ServiceCacheKey::single($id),
            ServiceCacheKey::withRelations($id),
            ServiceCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['services', "service:{$id}"]);
    }

    private function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            ServiceCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['services']);
    }
}
