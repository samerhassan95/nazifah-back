<?php

namespace Modules\Admin\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Admin\Interfaces\ServiceRepositoryInterface;
use Modules\Service\Models\Service;

class ServiceRepository implements ServiceRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $query = Service::with('category');

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (isset($filters['vendor_id'])) {
            $query->where('vendor_id', $filters['vendor_id']);
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

    public function find(int $id): ?Service
    {
        return Service::with('category')->find($id);
    }

    public function create(array $data): Service
    {
        return Service::create($data);
    }

    public function update(Service $service, array $data): bool
    {
        return $service->update($data);
    }

    public function delete(Service $service): bool
    {
        return $service->delete();
    }

    public function toggleStatus(Service $service): bool
    {
        return $service->update(['is_active' => ! $service->is_active]);
    }

    public function getStatistics(): array
    {
        return [
            'total' => Service::count(),
            'active' => 0,
            'inactive' => 0,
        ];
    }
}
