<?php

namespace Modules\Discount\Repositories;

use App\Cache\CacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Discount\Cache\DiscountCacheKey;
use Modules\Discount\Interfaces\DiscountRepositoryInterface;
use Modules\Discount\Models\Discount;

class DiscountRepository implements DiscountRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $key = DiscountCacheKey::filteredCollection($filters);

        return CacheManager::remember(
            key: $key,
            ttl: CacheManager::TTL_SHORT,
            callback: function () use ($filters) {
                $query = Discount::query();

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
            tags: ['discounts']
        );
    }

    public function find(int $id): ?Discount
    {
        return CacheManager::remember(
            key: DiscountCacheKey::withRelations($id),
            ttl: CacheManager::TTL_SHORT,
            callback: fn () => Discount::find($id),
            tags: ['discounts', "discount:{$id}"]
        );
    }

    public function create(array $data): Discount
    {
        $discount = Discount::create($data);
        $this->clearCollectionCache();

        return $discount;
    }

    public function update(Discount $discount, array $data): bool
    {
        $result = $discount->update($data);
        $this->clearCache($discount->id);

        return $result;
    }

    public function delete(Discount $discount): bool
    {
        $id = $discount->id;
        $result = $discount->delete();
        $this->clearCache($id);

        return $result;
    }

    // ─── Private helpers ─────────────────────────────────────────────

    private function clearCache(int $id): void
    {
        CacheManager::forgetKeys([
            DiscountCacheKey::single($id),
            DiscountCacheKey::withRelations($id),
            DiscountCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['discounts', "discount:{$id}"]);
    }

    private function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            DiscountCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['discounts']);
    }
}
