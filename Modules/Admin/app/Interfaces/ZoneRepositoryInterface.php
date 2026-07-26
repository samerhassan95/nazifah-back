<?php

namespace Modules\Admin\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Zone\Models\Zone;

interface ZoneRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?Zone;

    public function create(array $data): Zone;

    public function update(Zone $zone, array $data): bool;

    public function delete(Zone $zone): bool;

    public function toggleStatus(Zone $zone): bool;

    public function getStatistics(): array;
}
