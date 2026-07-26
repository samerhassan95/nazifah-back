<?php

namespace Modules\Owner\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Owner\Interfaces\OwnerRepositoryInterface;
use Modules\Owner\Models\Owner;

class OwnerRepository implements OwnerRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $query = Owner::query();

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        return $query->paginate($filters['per_page'] ?? 15);
    }

    public function find(int $id): ?Owner
    {
        return Owner::find($id);
    }

    public function create(array $data): Owner
    {
        return Owner::create($data);
    }

    public function update(Owner $owner, array $data): bool
    {
        return $owner->update($data);
    }

    public function delete(Owner $owner): bool
    {
        return $owner->delete();
    }
}
