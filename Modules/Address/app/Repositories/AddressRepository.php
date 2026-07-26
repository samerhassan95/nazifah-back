<?php

namespace Modules\Address\Repositories;

use App\Cache\CacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Address\Cache\AddressCacheKey;
use Modules\Address\Interfaces\AddressRepositoryInterface;
use Modules\Address\Models\Address;

class AddressRepository implements AddressRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $key = AddressCacheKey::filteredCollection($filters);

        return CacheManager::remember(
            key: $key,
            ttl: CacheManager::TTL_SHORT,
            callback: function () use ($filters) {
                $query = Address::query();

                if (isset($filters['search'])) {
                    $search = $filters['search'];
                    $query->where(function ($q) use ($search) {
                        $q->where('title', 'like', "%{$search}%")
                            ->orWhere('address_text', 'like', "%{$search}%")
                            ->orWhere('city', 'like', "%{$search}%");
                    });
                }

                if (isset($filters['client_id'])) {
                    $query->where('client_id', $filters['client_id']);
                }

                if (isset($filters['is_active'])) {
                    $query->where('is_active', $filters['is_active']);
                }

                $sortBy = $filters['sort_by'] ?? 'created_at';
                $sortOrder = $filters['sort_order'] ?? 'desc';
                $query->orderBy($sortBy, $sortOrder);

                return $query->paginate($filters['per_page'] ?? 15);
            },
            tags: ['addresses']
        );
    }

    public function find(int $id): ?Address
    {
        return CacheManager::remember(
            key: AddressCacheKey::withRelations($id),
            ttl: CacheManager::TTL_SHORT,
            callback: fn () => Address::with(['client', 'zone'])->find($id),
            tags: ['addresses', "address:{$id}"]
        );
    }

    public function create(array $data): Address
    {
        $address = Address::create($data);
        $this->clearCollectionCache();

        return $address;
    }

    public function update(Address $address, array $data): bool
    {
        $result = $address->update($data);
        $this->clearCache($address->id);

        return $result;
    }

    public function delete(Address $address): bool
    {
        $id = $address->id;
        $result = $address->delete();
        $this->clearCache($id);

        return $result;
    }

    // ─── Private helpers ─────────────────────────────────────────────

    private function clearCache(int $id): void
    {
        CacheManager::forgetKeys([
            AddressCacheKey::single($id),
            AddressCacheKey::withRelations($id),
            AddressCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['addresses', "address:{$id}"]);
    }

    private function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            AddressCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['addresses']);
    }
}
