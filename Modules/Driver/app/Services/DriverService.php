<?php

namespace Modules\Driver\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Driver\Interfaces\DriverRepositoryInterface;
use Modules\Driver\Models\Driver;

class DriverService
{
    public function __construct(
        private DriverRepositoryInterface $driverRepository
    ) {}

    public function getAllDrivers(array $filters = []): LengthAwarePaginator
    {
        return $this->driverRepository->all($filters);
    }

    public function getDriverById(int $id): ?Driver
    {
        return $this->driverRepository->find($id);
    }

    public function createDriver(array $data): Driver
    {
        return $this->driverRepository->create($data);
    }

    public function updateDriver(int $id, array $data): ?Driver
    {
        $driver = $this->driverRepository->find($id);

        if (! $driver) {
            return null;
        }

        $this->driverRepository->update($driver, $data);

        return $driver->fresh();
    }

    public function deleteDriver(int $id): bool
    {
        $driver = $this->driverRepository->find($id);

        if (! $driver) {
            return false;
        }

        return $this->driverRepository->delete($driver);
    }
}
