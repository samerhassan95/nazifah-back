<?php

namespace Modules\Admin\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Owner\Models\Owner;

interface OwnerRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?Owner;

    public function create(array $data): Owner;

    public function update(Owner $owner, array $data): bool;

    public function delete(Owner $owner): bool;

    public function toggleStatus(Owner $owner): bool;

    public function getStatistics(): array;
}
