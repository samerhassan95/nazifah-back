<?php

namespace Modules\Admin\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Branch\Models\Branch;

interface BranchRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?Branch;

    public function create(array $data): Branch;

    public function update(Branch $branch, array $data): bool;

    public function delete(Branch $branch): bool;

    public function toggleStatus(Branch $branch): bool;

    public function getStatistics(): array;
}
