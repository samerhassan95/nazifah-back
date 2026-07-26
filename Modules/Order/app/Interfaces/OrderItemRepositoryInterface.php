<?php

namespace Modules\Order\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Order\Models\OrderItem;

interface OrderItemRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?OrderItem;

    public function create(array $data): OrderItem;

    public function update(OrderItem $orderItem, array $data): bool;

    public function delete(OrderItem $orderItem): bool;
}
