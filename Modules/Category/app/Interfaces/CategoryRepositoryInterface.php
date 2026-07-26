<?php

namespace Modules\Category\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Category\Models\Category;

interface CategoryRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?Category;

    public function create(array $data): Category;

    public function update(Category $category, array $data): bool;

    public function delete(Category $category): bool;
}
