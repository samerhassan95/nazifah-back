<?php

namespace Modules\Owner\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Owner\Interfaces\OwnerRepositoryInterface;
use Modules\Owner\Models\Owner;

class OwnerService
{
    public function __construct(
        private OwnerRepositoryInterface $ownerRepository
    ) {}

    public function getAllOwners(array $filters = []): LengthAwarePaginator
    {
        return $this->ownerRepository->all($filters);
    }

    public function getOwnerById(int $id): ?Owner
    {
        return $this->ownerRepository->find($id);
    }

    public function createOwner(array $data): Owner
    {
        return $this->ownerRepository->create($data);
    }

    public function updateOwner(int $id, array $data): ?Owner
    {
        $owner = $this->ownerRepository->find($id);

        if (! $owner) {
            return null;
        }

        $this->ownerRepository->update($owner, $data);

        return $owner->fresh();
    }

    public function deleteOwner(int $id): bool
    {
        $owner = $this->ownerRepository->find($id);

        if (! $owner) {
            return false;
        }

        return $this->ownerRepository->delete($owner);
    }
}
