<?php

namespace Modules\Admin\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Address\Models\Address;
use Modules\Admin\Interfaces\AddressRepositoryInterface;

class AddressRepository implements AddressRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $query = Address::query();

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

    public function find(int $id): ?Address
    {
        return Address::find($id);
    }

    public function create(array $data): Address
    {
        return Address::create($data);
    }

    public function update(Address $address, array $data): bool
    {
        return $address->update($data);
    }

    public function delete(Address $address): bool
    {
        return $address->delete();
    }

    public function toggleStatus(Address $address): bool
    {
        return $address->update(['is_active' => ! $address->is_active]);
    }

    public function getStatistics(): array
    {
        return [
            'total' => Address::count(),
            'active' => Address::where('is_active', true)->count(),
            'inactive' => Address::where('is_active', false)->count(),
        ];
    }
}
