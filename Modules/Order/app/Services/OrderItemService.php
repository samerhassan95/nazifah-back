<?php

namespace Modules\Order\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Order\Interfaces\OrderItemRepositoryInterface;
use Modules\Order\Models\OrderItem;

class OrderItemService
{
    public function __construct(
        private OrderItemRepositoryInterface $orderItemRepository
    ) {}

    public function getAllOrderItems(array $filters = []): LengthAwarePaginator
    {
        return $this->orderItemRepository->all($filters);
    }

    public function getOrderItemById(int $id): ?OrderItem
    {
        return $this->orderItemRepository->find($id);
    }

    public function createOrderItem(array $data): OrderItem
    {
        return $this->orderItemRepository->create($data);
    }

    public function updateOrderItem(int $id, array $data): ?OrderItem
    {
        $orderItem = $this->orderItemRepository->find($id);

        if (! $orderItem) {
            return null;
        }

        $this->orderItemRepository->update($orderItem, $data);

        return $orderItem->fresh();
    }

    public function deleteOrderItem(int $id): bool
    {
        $orderItem = $this->orderItemRepository->find($id);

        if (! $orderItem) {
            return false;
        }

        return $this->orderItemRepository->delete($orderItem);
    }
}
