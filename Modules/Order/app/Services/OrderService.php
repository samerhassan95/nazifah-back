<?php

namespace Modules\Order\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Order\Interfaces\OrderRepositoryInterface;
use Modules\Order\Models\Order;

class OrderService
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository
    ) {}

    public function getAllOrders(array $filters = []): LengthAwarePaginator
    {
        return $this->orderRepository->all($filters);
    }

    public function getOrderById(int $id): ?Order
    {
        return $this->orderRepository->find($id);
    }

    public function createOrder(array $data): Order
    {
        return $this->orderRepository->create($data);
    }

    public function updateOrder(int $id, array $data): ?Order
    {
        $order = $this->orderRepository->find($id);

        if (! $order) {
            return null;
        }

        $this->orderRepository->update($order, $data);

        return $order->fresh();
    }

    public function deleteOrder(int $id): bool
    {
        $order = $this->orderRepository->find($id);

        if (! $order) {
            return false;
        }

        return $this->orderRepository->delete($order);
    }

    public function getOrderWithRelations(int $id, array $relations = []): ?Order
    {
        return $this->orderRepository->findWithRelations($id, $relations);
    }

    public function getVendorOrders(array $branchIds, array $filters = []): LengthAwarePaginator
    {
        return $this->orderRepository->paginateForVendor($branchIds, $filters);
    }
}
