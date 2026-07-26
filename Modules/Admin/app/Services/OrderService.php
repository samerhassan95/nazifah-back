<?php

namespace Modules\Admin\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Admin\Interfaces\OrderRepositoryInterface;
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

    public function getAllPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $filters = array_merge($filters, ['per_page' => $perPage]);

        return $this->orderRepository->all($filters);
    }

    public function getOrderById(int $id): ?Order
    {
        return $this->orderRepository->find($id);
    }

    public function find(int $id): ?Order
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

    public function toggleOrderStatus(int $id): ?Order
    {
        $order = $this->orderRepository->find($id);

        if (! $order) {
            return null;
        }

        $this->orderRepository->toggleStatus($order);

        return $order->fresh();
    }

    public function getStatistics(): array
    {
        return $this->orderRepository->getStatistics();
    }
}
