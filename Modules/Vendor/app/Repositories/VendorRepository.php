<?php

namespace Modules\Vendor\Repositories;

use App\Cache\CacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Vendor\Cache\VendorCacheKey;
use Modules\Vendor\Interfaces\VendorRepositoryInterface;
use Modules\Vendor\Models\Vendor;

class VendorRepository implements VendorRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $key = VendorCacheKey::filteredCollection($filters);

        return CacheManager::remember(
            key: $key,
            ttl: CacheManager::TTL_MEDIUM,
            callback: function () use ($filters) {
                $query = Vendor::query();

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
            tags: ['vendors']
        );
    }

    public function find(int $id): ?Vendor
    {
        return CacheManager::remember(
            key: VendorCacheKey::withRelations($id),
            ttl: CacheManager::TTL_MEDIUM,
            callback: fn () => Vendor::with(['branches', 'services', 'pieces'])->find($id),
            tags: ['vendors', "vendor:{$id}"]
        );
    }

    public function create(array $data): Vendor
    {
        $vendor = Vendor::create($data);
        $this->clearCollectionCache();

        return $vendor;
    }

    public function update(Vendor $vendor, array $data): bool
    {
        $result = $vendor->update($data);
        $this->clearCache($vendor->id);

        return $result;
    }

    public function delete(Vendor $vendor): bool
    {
        $id = $vendor->id;
        $result = $vendor->delete();
        $this->clearCache($id);

        return $result;
    }

    // ─── Private helpers ─────────────────────────────────────────────

    private function clearCache(int $id): void
    {
        CacheManager::forgetKeys([
            VendorCacheKey::single($id),
            VendorCacheKey::withRelations($id),
            VendorCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['vendors', "vendor:{$id}"]);
    }

    private function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            VendorCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['vendors']);
    }
}
