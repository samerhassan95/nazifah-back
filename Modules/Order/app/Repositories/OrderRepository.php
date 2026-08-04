<?php

namespace Modules\Order\Repositories;

use App\Cache\CacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Order\Cache\OrderCacheKey;
use Modules\Order\Interfaces\OrderRepositoryInterface;
use Modules\Order\Models\Order;

class OrderRepository implements OrderRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $key = OrderCacheKey::filteredCollection($filters);

        return CacheManager::remember(
            key: $key,
            ttl: CacheManager::TTL_REALTIME,
            callback: function () use ($filters) {
                $query = Order::query();

                if (isset($filters['search'])) {
                    $search = $filters['search'];
                    $query->where('order_number', 'like', "%{$search}%");
                }

                $sortBy = $filters['sort_by'] ?? 'created_at';
                $sortOrder = $filters['sort_order'] ?? 'desc';
                $query->orderBy($sortBy, $sortOrder);

                return $query->paginate($filters['per_page'] ?? 15);
            },
            tags: ['orders']
        );
    }

    public function find(int $id): ?Order
    {
        return CacheManager::remember(
            key: OrderCacheKey::single($id),
            ttl: CacheManager::TTL_REALTIME,
            callback: fn () => Order::find($id),
            tags: ['orders', "order:{$id}"]
        );
    }

    public function findWithRelations(int $id, array $relations = []): ?Order
    {
        return CacheManager::remember(
            key: OrderCacheKey::withRelations($id),
            ttl: CacheManager::TTL_REALTIME,
            callback: fn () => Order::with($relations)->find($id),
            tags: ['orders', "order:{$id}"]
        );
    }

    public function paginateForVendor(array $branchIds, array $filters = []): LengthAwarePaginator
    {
        // Custom variant key for vendor lists
        $key = OrderCacheKey::filteredCollection(array_merge($filters, ['branches' => $branchIds]));

        return CacheManager::remember(
            key: $key,
            ttl: CacheManager::TTL_REALTIME,
            callback: function () use ($branchIds, $filters) {
                $query = Order::whereIn('branch_id', $branchIds)
                    ->with($filters['with'] ?? [])
                    ->orderBy('updated_at', 'desc');

                if (! empty($filters['vendor_tab'])) {
                    match ($filters['vendor_tab']) {
                        'current' => $query->vendorCurrent(),
                        'completed' => $query->vendorCompleted(),
                        default => null,
                    };
                } elseif (isset($filters['status'])) {
                    $status = $filters['status'];
                    if (is_array($status)) {
                        $query->whereIn('status', $status);
                    } else {
                        $query->where('status', $status);
                    }
                }

                return $query->paginate($filters['limit'] ?? 15);
            },
            tags: ['orders']
        );
    }

    public function create(array $data): Order
    {
        $order = Order::create($data);
        $this->clearCollectionCache();

        return $order;
    }

    public function update(Order $order, array $data): bool
    {
        $result = $order->update($data);
        $this->clearCache($order->id);

        return $result;
    }

    public function delete(Order $order): bool
    {
        $id = $order->id;
        $result = $order->delete();
        $this->clearCache($id);

        return $result;
    }

    // ─── Private helpers ─────────────────────────────────────────────

    private function clearCache(int $id): void
    {
        CacheManager::forgetKeys([
            OrderCacheKey::single($id),
            OrderCacheKey::withRelations($id),
            OrderCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['orders', "order:{$id}"]);
    }

    private function clearCollectionCache(): void
    {
        CacheManager::forgetKeys([
            OrderCacheKey::collection(),
        ]);
        CacheManager::forgetByTags(['orders']);
    }
}
