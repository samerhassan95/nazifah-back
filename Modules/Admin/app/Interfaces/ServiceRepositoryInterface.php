<?php

namespace Modules\Admin\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Service\Models\Service;

interface ServiceRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?Service;

    public function create(array $data): Service;

    public function update(Service $service, array $data): bool;

    public function delete(Service $service): bool;

    public function toggleStatus(Service $service): bool;

    public function getStatistics(): array;
}
