<?php

namespace Modules\Admin\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Admin\Interfaces\BranchRepositoryInterface;
use Modules\Branch\Models\Branch;

class BranchRepository implements BranchRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $query = Branch::query();

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        return $query->paginate($filters['per_page'] ?? 15);
    }

    public function find(int $id): ?Branch
    {
        return Branch::find($id);
    }

    public function create(array $data): Branch
    {
        return Branch::create($data);
    }

    public function update(Branch $branch, array $data): bool
    {
        return $branch->update($data);
    }

    public function delete(Branch $branch): bool
    {
        return $branch->delete();
    }

    public function toggleStatus(Branch $branch): bool
    {
        return $branch->update(['is_active' => ! $branch->is_active]);
    }

    public function getStatistics(): array
    {
        return [
            'total' => Branch::count(),
            'active' => Branch::where('is_active', true)->count(),
            'inactive' => Branch::where('is_active', false)->count(),
        ];
    }
}
