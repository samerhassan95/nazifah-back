<?php

namespace Modules\Service\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Service\Models\Product;

interface ProductRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?Product;

    public function create(array $data): Product;

    public function update(Product $product, array $data): bool;

    public function delete(Product $product): bool;
}
