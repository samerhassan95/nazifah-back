<?php

namespace Modules\Branch\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Branch\Models\Branch;

interface BranchRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?Branch;

    public function findWithRelations(int $id, array $relations = []): ?Branch;

    public function getStatistics(): array;

    public function getAvailableInZone(int $zoneId);

    public function getAvailableInZoneWithService(int $zoneId, int $serviceId);

    public function getAvailableInZoneWithServices(int $zoneId, array $serviceIds);

    public function create(array $data): Branch;

    public function update(Branch $branch, array $data): bool;

    public function delete(Branch $branch): bool;
}
