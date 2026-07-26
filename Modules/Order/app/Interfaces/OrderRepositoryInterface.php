<?php

namespace Modules\Order\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Order\Models\Order;

interface OrderRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?Order;

    public function findWithRelations(int $id, array $relations = []): ?Order;

    public function paginateForVendor(array $branchIds, array $filters = []): LengthAwarePaginator;

    public function create(array $data): Order;

    public function update(Order $order, array $data): bool;

    public function delete(Order $order): bool;
}
