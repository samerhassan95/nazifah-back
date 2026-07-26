<?php

namespace Modules\Category\Repositories;

use App\Cache\CacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Category\Cache\CategoryCacheKey;
use Modules\Category\Interfaces\CategoryRepositoryInterface;
use Modules\Category\Models\Category;

class CategoryRepository implements CategoryRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $key = CategoryCacheKey::filteredCollection($filters);

        return CacheManager::remember(
            key: $key,
            ttl: CacheManager::TTL_LONG,
            callback: function () use ($filters) {
                $query = Category::query();

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
            tags: ['categories']
        );
    }

    public function find(int $id): ?Category
    {
        return CacheManager::remember(
            key: CategoryCacheKey::withRelations($id),
            ttl: CacheManager::TTL_LONG,
            callback: fn () => Category::with(['services'])->find($id),
            tags: ['categories', "category:{$id}"]
        );
    }

    public function create(array $data): Category
    {
        $category = Category::create($data);
        $this->clearCollectionCache();

        return $category;
    }

    public function update(Category $category, array $data): bool
    {
        $result = $category->update($data);
        $this->clearCache($category->id);

        return $result;
    }

    public function delete(Category $category): bool
    {
        $id = $category->id;
        $result = $category->delete();
        $this->clearCache($id);

        return $result;
    }

    // ─── Private helpers ─────────────────────────────────────────────

    private function clearCache(int $id): void
    {
        CacheManager::forgetKeys([
            CategoryCacheKey::single($id),
            CategoryCacheKey::withRelations($id),
            CategoryCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['categories', "category:{$id}"]);
    }

    private function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            CategoryCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['categories']);
    }
}
