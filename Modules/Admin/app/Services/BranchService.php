<?php

namespace Modules\Admin\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Admin\Interfaces\BranchRepositoryInterface;
use Modules\Branch\Models\Branch;

class BranchService
{
    public function __construct(
        private BranchRepositoryInterface $branchRepository
    ) {}

    public function getAllBranchs(array $filters = []): LengthAwarePaginator
    {
        return $this->Repository->all($filters);
    }

    public function getBranchById(int $id): ?Branch
    {
        return $this->Repository->find($id);
    }

    public function createBranch(array $data): Branch
    {
        return $this->Repository->create($data);
    }

    public function updateBranch(int $id, array $data): ?Branch
    {
        $branch = $this->Repository->find($id);

        if (! $branch) {
            return null;
        }

        $this->Repository->update($branch, $data);

        return $branch->fresh();
    }

    public function deleteBranch(int $id): bool
    {
        $branch = $this->Repository->find($id);

        if (! $branch) {
            return false;
        }

        return $this->Repository->delete($branch);
    }

    public function toggleBranchStatus(int $id): ?Branch
    {
        $branch = $this->Repository->find($id);

        if (! $branch) {
            return null;
        }

        $this->Repository->toggleStatus($branch);

        return $branch->fresh();
    }

    public function getStatistics(): array
    {
        return $this->Repository->getStatistics();
    }
}
