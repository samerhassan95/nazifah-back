<?php

namespace Modules\BannerOffer\Repositories;

use App\Cache\CacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\BannerOffer\Cache\BannerOfferCacheKey;
use Modules\BannerOffer\Interfaces\BannerOfferRepositoryInterface;
use Modules\BannerOffer\Models\BannerOffer;

class BannerOfferRepository implements BannerOfferRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $key = BannerOfferCacheKey::filteredCollection($filters);

        return CacheManager::remember(
            key: $key,
            ttl: CacheManager::TTL_LONG,
            callback: function () use ($filters) {
                $query = BannerOffer::query();

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
            tags: ['banneroffers']
        );
    }

    public function find(int $id): ?BannerOffer
    {
        return CacheManager::remember(
            key: BannerOfferCacheKey::withRelations($id),
            ttl: CacheManager::TTL_LONG,
            callback: fn () => BannerOffer::find($id),
            tags: ['banneroffers', "banneroffer:{$id}"]
        );
    }

    public function create(array $data): BannerOffer
    {
        $bannerOffer = BannerOffer::create($data);
        $this->clearCollectionCache();

        return $bannerOffer;
    }

    public function update(BannerOffer $bannerOffer, array $data): bool
    {
        $result = $bannerOffer->update($data);
        $this->clearCache($bannerOffer->id);

        return $result;
    }

    public function delete(BannerOffer $bannerOffer): bool
    {
        $id = $bannerOffer->id;
        $result = $bannerOffer->delete();
        $this->clearCache($id);

        return $result;
    }

    // ─── Private helpers ─────────────────────────────────────────────

    private function clearCache(int $id): void
    {
        CacheManager::forgetKeys([
            BannerOfferCacheKey::single($id),
            BannerOfferCacheKey::withRelations($id),
            BannerOfferCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['banneroffers', "banneroffer:{$id}"]);
    }

    private function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            BannerOfferCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['banneroffers']);
    }
}
