<?php

namespace Modules\Admin\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Ad\Models\Ad;
use Modules\Admin\Interfaces\AdRepositoryInterface;

class AdRepository implements AdRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $query = Ad::query();

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

    public function find(int $id): ?Ad
    {
        return Ad::find($id);
    }

    public function create(array $data): Ad
    {
        return Ad::create($data);
    }

    public function update(Ad $ad, array $data): bool
    {
        return $ad->update($data);
    }

    public function delete(Ad $ad): bool
    {
        return $ad->delete();
    }

    public function toggleStatus(Ad $ad): bool
    {
        return $ad->update(['is_active' => ! $ad->is_active]);
    }

    public function getStatistics(): array
    {
        return [
            'total' => Ad::count(),
            'active' => Ad::where('is_active', true)->count(),
            'inactive' => Ad::where('is_active', false)->count(),
        ];
    }
}
