<?php

namespace Modules\Admin\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Admin\Interfaces\ServiceRepositoryInterface;
use Modules\Service\Models\Service;

class ServiceService
{
    public function __construct(
        private ServiceRepositoryInterface $serviceRepository
    ) {}

    public function getAllServices(array $filters = []): LengthAwarePaginator
    {
        return $this->Repository->all($filters);
    }

    public function getServiceById(int $id): ?Service
    {
        return $this->Repository->find($id);
    }

    public function createService(array $data): Service
    {
        return $this->Repository->create($data);
    }

    public function updateService(int $id, array $data): ?Service
    {
        $service = $this->Repository->find($id);

        if (! $service) {
            return null;
        }

        $this->Repository->update($service, $data);

        return $service->fresh();
    }

    public function deleteService(int $id): bool
    {
        $service = $this->Repository->find($id);

        if (! $service) {
            return false;
        }

        return $this->Repository->delete($service);
    }

    public function toggleServiceStatus(int $id): ?Service
    {
        $service = $this->Repository->find($id);

        if (! $service) {
            return null;
        }

        $this->Repository->toggleStatus($service);

        return $service->fresh();
    }

    public function getStatistics(): array
    {
        return $this->Repository->getStatistics();
    }
}
