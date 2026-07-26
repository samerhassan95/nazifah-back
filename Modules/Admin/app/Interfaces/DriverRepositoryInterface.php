<?php

namespace Modules\Admin\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Driver\Models\Driver;

interface DriverRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?Driver;

    public function create(array $data): Driver;

    public function update(Driver $driver, array $data): bool;

    public function delete(Driver $driver): bool;

    public function getStatistics(): array;
}
