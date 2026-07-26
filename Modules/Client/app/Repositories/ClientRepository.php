<?php

namespace Modules\Client\Repositories;

use App\Cache\CacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Client\Cache\ClientCacheKey;
use Modules\Client\Interfaces\ClientRepositoryInterface;
use Modules\Client\Models\Client;

class ClientRepository implements ClientRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $key = ClientCacheKey::filteredCollection($filters);

        return CacheManager::remember(
            key: $key,
            ttl: CacheManager::TTL_SHORT,
            callback: function () use ($filters) {
                $query = Client::query();

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
            tags: ['clients']
        );
    }

    public function find(int $id): ?Client
    {
        return CacheManager::remember(
            key: ClientCacheKey::withRelations($id),
            ttl: CacheManager::TTL_SHORT,
            callback: fn () => Client::with(['addresses', 'orders'])->find($id),
            tags: ['clients', "client:{$id}"]
        );
    }

    public function create(array $data): Client
    {
        $client = Client::create($data);
        $this->clearCollectionCache();

        return $client;
    }

    public function update(Client $client, array $data): bool
    {
        $result = $client->update($data);
        $this->clearCache($client->id);

        return $result;
    }

    public function delete(Client $client): bool
    {
        $id = $client->id;
        $result = $client->delete();
        $this->clearCache($id);

        return $result;
    }

    // ─── Private helpers ─────────────────────────────────────────────

    private function clearCache(int $id): void
    {
        CacheManager::forgetKeys([
            ClientCacheKey::single($id),
            ClientCacheKey::withRelations($id),
            ClientCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['clients', "client:{$id}"]);
    }

    private function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            ClientCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['clients']);
    }
}
