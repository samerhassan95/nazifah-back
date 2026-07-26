<?php

namespace Modules\Admin\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Admin\Interfaces\DriverRepositoryInterface;
use Modules\Driver\Models\Driver;

class DriverService
{
    public function __construct(
        private DriverRepositoryInterface $driverRepository
    ) {}

    public function getAllPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $filters['per_page'] = $perPage;

        return $this->driverRepository->all($filters);
    }

    public function find(int $id): ?Driver
    {
        return $this->driverRepository->find($id);
    }

    public function create(array $data): Driver
    {
        return $this->driverRepository->create($data);
    }

    public function update(int $id, array $data): ?Driver
    {
        $driver = $this->driverRepository->find($id);

        if (! $driver) {
            return null;
        }

        $this->driverRepository->update($driver, $data);

        return $driver->fresh();
    }

    public function delete(int $id): bool
    {
        $driver = $this->driverRepository->find($id);

        if (! $driver) {
            return false;
        }

        return $this->driverRepository->delete($driver);
    }

    public function getStatistics(): array
    {
        return $this->driverRepository->getStatistics();
    }
}
