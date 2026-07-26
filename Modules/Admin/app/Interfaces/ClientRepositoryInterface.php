<?php

namespace Modules\Admin\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Client\Models\Client;

interface ClientRepositoryInterface
{
    public function all(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function find(int $id): ?Client;

    public function create(array $data): Client;

    public function update(Client $client, array $data): bool;

    public function delete(Client $client): bool;

    public function toggleStatus(Client $client): Client;

    public function getStatistics(?int $zoneId = null): array;
}
