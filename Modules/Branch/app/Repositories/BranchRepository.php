<?php

namespace Modules\Branch\Repositories;

use App\Cache\CacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Branch\Cache\BranchCacheKey;
use Modules\Branch\Interfaces\BranchRepositoryInterface;
use Modules\Branch\Models\Branch;

class BranchRepository implements BranchRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $key = BranchCacheKey::filteredCollection($filters);

        return CacheManager::remember(
            key: $key,
            ttl: CacheManager::TTL_MEDIUM,
            callback: function () use ($filters) {
                $query = Branch::query();

                if (isset($filters['vendor_id'])) {
                    $query->where('vendor_id', $filters['vendor_id']);
                }

                if (! empty($filters['zone_id'])) {
                    $query->where('zone_id', $filters['zone_id']);
                }

                if (! empty($filters['zone_ids']) && is_array($filters['zone_ids'])) {
                    $query->whereIn('zone_id', array_map('intval', $filters['zone_ids']));
                }

                if (! empty($filters['zone_name'])) {
                    $zoneName = $filters['zone_name'];
                    $query->whereHas('zone', function ($q) use ($zoneName) {
                        $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(name, '$.ar')) = ?", [$zoneName])
                            ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(name, '$.en')) = ?", [$zoneName]);
                    });
                }

                if (isset($filters['search'])) {
                    $search = $filters['search'];
                    $query->where(function ($q) use ($search) {
                        $q->whereRaw("JSON_EXTRACT(name, '$$.ar') LIKE ?", ["%{$search}%"])
                            ->orWhereRaw("JSON_EXTRACT(name, '$$.en') LIKE ?", ["%{$search}%"])
                            ->orWhere('phone_number', 'like', "%{$search}%");
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
            tags: ['branches']
        );
    }

    public function find(int $id): ?Branch
    {
        return CacheManager::remember(
            key: BranchCacheKey::single($id),
            ttl: CacheManager::TTL_MEDIUM,
            callback: fn () => Branch::find($id),
            tags: ['branches', "branch:{$id}"]
        );
    }

    public function findWithRelations(int $id, array $relations = []): ?Branch
    {
        return CacheManager::remember(
            key: BranchCacheKey::withRelations($id),
            ttl: CacheManager::TTL_MEDIUM,
            callback: fn () => Branch::with($relations)->find($id),
            tags: ['branches', "branch:{$id}"]
        );
    }

    public function getStatistics(): array
    {
        return CacheManager::remember(
            key: BranchCacheKey::statistics(),
            ttl: CacheManager::TTL_SHORT,
            callback: function () {
                return [
                    'total_branches' => Branch::count(),
                    'active_branches' => Branch::where('is_active', true)->count(),
                    'inactive_branches' => Branch::where('is_active', false)->count(),
                ];
            },
            tags: ['branches']
        );
    }

    public function getAvailableInZone(int $zoneId)
    {
        return CacheManager::remember(
            key: "branch:v1:zone:{$zoneId}:available",
            ttl: CacheManager::TTL_MEDIUM,
            callback: fn () => Branch::where('zone_id', $zoneId)
                ->where('is_active', true)
                ->whereHas('vendor', function ($query) {
                    $query->where('is_active', true)->where('is_banned', false);
                })
                ->with(['vendor', 'zone'])
                ->get(),
            tags: ['branches', 'zones', 'vendors']
        );
    }

    public function getAvailableInZoneWithService(int $zoneId, int $serviceId)
    {
        return CacheManager::remember(
            key: "branch:v1:zone:{$zoneId}:service:{$serviceId}:available",
            ttl: CacheManager::TTL_MEDIUM,
            callback: fn () => Branch::where('zone_id', $zoneId)
                ->where('is_active', true)
                ->whereHas('vendor', function ($query) {
                    $query->where('is_active', true)->where('is_banned', false);
                })
                ->whereHas('services', function ($query) use ($serviceId) {
                    $query->where('services.id', $serviceId);
                })
                ->with(['vendor', 'zone', 'services' => function ($query) use ($serviceId) {
                    $query->where('services.id', $serviceId);
                }])
                ->get(),
            tags: ['branches', 'zones', 'vendors', 'services']
        );
    }

    public function getAvailableInZoneWithServices(int $zoneId, array $serviceIds)
    {
        $hash = md5(serialize($serviceIds));

        return CacheManager::remember(
            key: "branch:v1:zone:{$zoneId}:services:{$hash}:available",
            ttl: CacheManager::TTL_MEDIUM,
            callback: fn () => Branch::where('zone_id', $zoneId)
                ->where('is_active', true)
                ->whereHas('vendor', function ($query) {
                    $query->where('is_active', true)->where('is_banned', false);
                })
                ->whereHas('services', function ($query) use ($serviceIds) {
                    $query->whereIn('services.id', $serviceIds);
                })
                ->with(['vendor', 'zone', 'services' => function ($query) use ($serviceIds) {
                    $query->whereIn('services.id', $serviceIds);
                }])
                ->get(),
            tags: ['branches', 'zones', 'vendors', 'services']
        );
    }

    public function create(array $data): Branch
    {
        $branch = Branch::create($data);
        $this->clearCollectionCache();

        return $branch;
    }

    public function update(Branch $branch, array $data): bool
    {
        $result = $branch->update($data);
        $this->clearCache($branch->id);

        return $result;
    }

    public function delete(Branch $branch): bool
    {
        $id = $branch->id;
        $result = $branch->delete();
        $this->clearCache($id);

        return $result;
    }

    // ─── Private helpers ─────────────────────────────────────────────

    private function clearCache(int $id): void
    {
        CacheManager::forgetKeys([
            BranchCacheKey::single($id),
            BranchCacheKey::withRelations($id),
            BranchCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['branches', "branch:{$id}"]);
    }

    private function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            BranchCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['branches']);
    }
}
