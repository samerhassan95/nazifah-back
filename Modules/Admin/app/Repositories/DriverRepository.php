<?php

namespace Modules\Admin\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Admin\Interfaces\DriverRepositoryInterface;
use Modules\Driver\Models\Driver;

class DriverRepository implements DriverRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $query = Driver::query();

        if (isset($filters['is_available'])) {
            $query->where('is_available', $filters['is_available']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->whereRaw("JSON_EXTRACT(full_name, '$.en') LIKE ?", ["%{$search}%"])
                    ->orWhereRaw("JSON_EXTRACT(full_name, '$.ar') LIKE ?", ["%{$search}%"])
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        return $query->paginate($filters['per_page'] ?? 15);
    }

    public function find(int $id): ?Driver
    {
        return Driver::find($id);
    }

    public function create(array $data): Driver
    {
        return Driver::create($data);
    }

    public function update(Driver $driver, array $data): bool
    {
        return $driver->update($data);
    }

    public function delete(Driver $driver): bool
    {
        return $driver->delete();
    }

    public function getStatistics(): array
    {
        return [
            'total' => Driver::count(),
            'available' => Driver::where('is_available', true)->count(),
        ];
    }
}
