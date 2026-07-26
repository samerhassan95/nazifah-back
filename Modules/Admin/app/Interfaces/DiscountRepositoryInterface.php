<?php

namespace Modules\Admin\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Discount\Models\Discount;

interface DiscountRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?Discount;

    public function create(array $data): Discount;

    public function update(Discount $discount, array $data): bool;

    public function delete(Discount $discount): bool;

    public function toggleStatus(Discount $discount): bool;

    public function getStatistics(): array;
}
