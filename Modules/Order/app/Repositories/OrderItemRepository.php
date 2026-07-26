<?php

namespace Modules\Order\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Order\Interfaces\OrderItemRepositoryInterface;
use Modules\Order\Models\OrderItem;

class OrderItemRepository implements OrderItemRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $query = OrderItem::query();

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        return $query->paginate($filters['per_page'] ?? 15);
    }

    public function find(int $id): ?OrderItem
    {
        return OrderItem::find($id);
    }

    public function create(array $data): OrderItem
    {
        return OrderItem::create($data);
    }

    public function update(OrderItem $orderItem, array $data): bool
    {
        return $orderItem->update($data);
    }

    public function delete(OrderItem $orderItem): bool
    {
        return $orderItem->delete();
    }
}
