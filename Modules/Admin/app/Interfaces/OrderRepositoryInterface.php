<?php

namespace Modules\Admin\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Order\Models\Order;

interface OrderRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?Order;

    public function create(array $data): Order;

    public function update(Order $order, array $data): bool;

    public function delete(Order $order): bool;

    public function toggleStatus(Order $order): bool;

    public function getStatistics(): array;
}
