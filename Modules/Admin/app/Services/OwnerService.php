<?php

namespace Modules\Admin\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Admin\Interfaces\OwnerRepositoryInterface;
use Modules\Owner\Models\Owner;

class OwnerService
{
    public function __construct(
        private OwnerRepositoryInterface $ownerRepository
    ) {}

    public function getAllPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $filters['per_page'] = $perPage;

        return $this->ownerRepository->all($filters);
    }

    public function find(int $id): ?Owner
    {
        return $this->ownerRepository->find($id);
    }

    public function create(array $data): Owner
    {
        return $this->ownerRepository->create($data);
    }

    public function update(int $id, array $data): ?Owner
    {
        $owner = $this->ownerRepository->find($id);

        if (! $owner) {
            return null;
        }

        $this->ownerRepository->update($owner, $data);

        return $owner->fresh();
    }

    public function delete(int $id): bool
    {
        $owner = $this->ownerRepository->find($id);

        if (! $owner) {
            return false;
        }

        return $this->ownerRepository->delete($owner);
    }

    public function toggleStatus(int $id): ?Owner
    {
        $owner = $this->ownerRepository->find($id);

        if (! $owner) {
            return null;
        }

        $this->ownerRepository->toggleStatus($owner);

        return $owner->fresh();
    }

    public function getStatistics(): array
    {
        return $this->ownerRepository->getStatistics();
    }
}
