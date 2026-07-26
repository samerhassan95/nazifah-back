<?php

namespace Modules\Admin\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Admin\Interfaces\ZoneRepositoryInterface;
use Modules\Zone\Models\Zone;

class ZoneService
{
    public function __construct(
        private ZoneRepositoryInterface $zoneRepository
    ) {}

    public function getAllZones(array $filters = []): LengthAwarePaginator
    {
        return $this->zoneRepository->all($filters);
    }

    public function getAllPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $filters['per_page'] = $perPage;

        return $this->zoneRepository->all($filters);
    }

    public function getZoneById(int $id): ?Zone
    {
        return $this->zoneRepository->find($id);
    }

    // Controller-friendly aliases/wrappers expected by Admin controllers
    public function find(int $id): ?Zone
    {
        return $this->getZoneById($id);
    }

    public function create(array $data): Zone
    {
        return $this->zoneRepository->create($data);
    }

    public function createZone(array $data): Zone
    {
        return $this->create($data);
    }

    public function update(int $id, array $data): ?Zone
    {
        return $this->updateZone($id, $data);
    }

    public function updateZone(int $id, array $data): ?Zone
    {
        $zone = $this->zoneRepository->find($id);

        if (! $zone) {
            return null;
        }

        $this->zoneRepository->update($zone, $data);

        return $zone->fresh();
    }

    public function delete(int $id): bool
    {
        return $this->deleteZone($id);
    }

    public function deleteZone(int $id): bool
    {
        $zone = $this->zoneRepository->find($id);

        if (! $zone) {
            return false;
        }

        return $this->zoneRepository->delete($zone);
    }

    public function toggleStatus(int $id): ?Zone
    {
        $zone = $this->zoneRepository->find($id);

        if (! $zone) {
            return null;
        }

        $this->zoneRepository->toggleStatus($zone);

        return $zone->fresh();
    }

    public function toggleZoneStatus(int $id): ?Zone
    {
        return $this->toggleStatus($id);
    }

    public function getStatistics(): array
    {
        return $this->zoneRepository->getStatistics();
    }
}
