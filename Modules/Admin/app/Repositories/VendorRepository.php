<?php

namespace Modules\Admin\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Admin\Interfaces\VendorRepositoryInterface;
use Modules\Vendor\Models\Vendor;

class VendorRepository implements VendorRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $query = Vendor::query();

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['is_verified'])) {
            $query->where('is_verified', $filters['is_verified']);
        }

        if (! empty($filters['verification_status'])) {
            switch ($filters['verification_status']) {
                case 'pending':
                    $query->where('is_verified', false)->whereNull('rejected_at');
                    break;
                case 'approved':
                    $query->where('is_verified', true);
                    break;
                case 'rejected':
                    $query->where('is_verified', false)->whereNotNull('rejected_at');
                    break;
            }
        }

        if (isset($filters['is_banned'])) {
            $query->where('is_banned', $filters['is_banned']);
        }

        if (isset($filters['category_id'])) {
            // Filter vendors by category through their branches' services
            $query->whereHas('branches.services', function ($q) use ($filters) {
                $q->where('category_id', $filters['category_id'])
                    ->where('services.is_active', true);
            });
        }

        if (isset($filters['zone_id'])) {
            $query->whereHas('branches', function ($q) use ($filters) {
                $q->where('zone_id', $filters['zone_id']);
            });
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        return $query->paginate($filters['per_page'] ?? 15);
    }

    public function find(int $id): ?Vendor
    {
        return Vendor::find($id);
    }

    public function create(array $data): Vendor
    {
        return Vendor::create($data);
    }

    public function update(Vendor $vendor, array $data): bool
    {
        return $vendor->update($data);
    }

    public function delete(Vendor $vendor): bool
    {
        return $vendor->delete();
    }

    public function toggleStatus(Vendor $vendor): bool
    {
        return $vendor->update(['is_active' => ! $vendor->is_active]);
    }

    public function getStatistics(): array
    {
        return [
            'total' => Vendor::count(),
            'active' => Vendor::where('is_active', true)->count(),
            'inactive' => Vendor::where('is_active', false)->count(),
        ];
    }
}
