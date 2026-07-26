<?php

namespace Modules\Admin\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Ad\Models\Ad;

interface AdRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?Ad;

    public function create(array $data): Ad;

    public function update(Ad $ad, array $data): bool;

    public function delete(Ad $ad): bool;

    public function toggleStatus(Ad $ad): bool;

    public function getStatistics(): array;
}
