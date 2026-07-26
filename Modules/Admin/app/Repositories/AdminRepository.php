<?php

namespace Modules\Admin\Repositories;

use App\Cache\CacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Admin\Cache\AdminCacheKey;
use Modules\Admin\Interfaces\AdminRepositoryInterface;
use Modules\Admin\Models\Admin;

class AdminRepository implements AdminRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $key = AdminCacheKey::filteredCollection($filters);

        return CacheManager::remember(
            key: $key,
            ttl: CacheManager::TTL_SHORT,
            callback: function () use ($filters) {
                $query = Admin::query();

                if (isset($filters['search'])) {
                    $search = $filters['search'];
                    $query->where(function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
                }

                $sortBy = $filters['sort_by'] ?? 'created_at';
                $sortOrder = $filters['sort_order'] ?? 'desc';
                $query->orderBy($sortBy, $sortOrder);

                return $query->paginate($filters['per_page'] ?? 15);
            },
            tags: ['admins']
        );
    }

    public function find(int $id): ?Admin
    {
        return CacheManager::remember(
            key: AdminCacheKey::single($id),
            ttl: CacheManager::TTL_MEDIUM,
            callback: fn () => Admin::find($id),
            tags: ['admins', "admin:{$id}"]
        );
    }

    public function create(array $data): Admin
    {
        $admin = Admin::create($data);
        $this->clearCollectionCache(); // new record → lists are stale

        return $admin;
    }

    public function update(Admin $admin, array $data): bool
    {
        $result = $admin->update($data);
        $this->clearCache($admin->id); // instance + lists

        return $result;
    }

    public function delete(Admin $admin): bool
    {
        $id = $admin->id;
        $result = $admin->delete();
        $this->clearCache($id);

        return $result;
    }

    // ─── Private helpers ─────────────────────────────────────────────

    private function clearCache(int $id): void
    {
        // Works on ALL drivers (file + redis)
        CacheManager::forgetKeys([
            AdminCacheKey::single($id),
            AdminCacheKey::collection(),
        ]);

        // Extra: tag-based flush for Redis (no-op on file driver)
        CacheManager::forgetByTags(['admins', "admin:{$id}"]);
    }

    private function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            AdminCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['admins']);
    }
}
