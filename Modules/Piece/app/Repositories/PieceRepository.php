<?php

namespace Modules\Piece\Repositories;

use App\Cache\CacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Piece\Cache\PieceCacheKey;
use Modules\Piece\Interfaces\PieceRepositoryInterface;
use Modules\Piece\Models\Piece;

class PieceRepository implements PieceRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $key = PieceCacheKey::filteredCollection($filters);

        return CacheManager::remember(
            key: $key,
            ttl: CacheManager::TTL_LONG,
            callback: function () use ($filters) {
                $query = Piece::with('services');

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
            tags: ['pieces']
        );
    }

    public function find(int $id): ?Piece
    {
        return CacheManager::remember(
            key: PieceCacheKey::withRelations($id),
            ttl: CacheManager::TTL_LONG,
            callback: fn () => Piece::with('services')->find($id),
            tags: ['pieces', "piece:{$id}"]
        );
    }

    public function create(array $data): Piece
    {
        $piece = Piece::create($data);
        $this->clearCollectionCache();

        return $piece;
    }

    public function update(Piece $piece, array $data): bool
    {
        $result = $piece->update($data);
        $this->clearCache($piece->id);

        return $result;
    }

    public function delete(Piece $piece): bool
    {
        $id = $piece->id;
        $result = $piece->delete();
        $this->clearCache($id);

        return $result;
    }

    // ─── Private helpers ─────────────────────────────────────────────

    private function clearCache(int $id): void
    {
        CacheManager::forgetKeys([
            PieceCacheKey::single($id),
            PieceCacheKey::withRelations($id),
            PieceCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['pieces', "piece:{$id}"]);
    }

    private function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            PieceCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['pieces']);
    }
}
