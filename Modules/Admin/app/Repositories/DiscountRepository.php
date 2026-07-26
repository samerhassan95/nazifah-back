<?php

namespace Modules\Admin\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Admin\Interfaces\DiscountRepositoryInterface;
use Modules\Discount\Models\Discount;

class DiscountRepository implements DiscountRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $query = Discount::query();

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

    public function find(int $id): ?Discount
    {
        return Discount::find($id);
    }

    public function create(array $data): Discount
    {
        return Discount::create($data);
    }

    public function update(Discount $discount, array $data): bool
    {
        return $discount->update($data);
    }

    public function delete(Discount $discount): bool
    {
        return $discount->delete();
    }

    public function toggleStatus(Discount $discount): bool
    {
        return $discount->update(['is_active' => ! $discount->is_active]);
    }

    public function getStatistics(): array
    {
        return [
            'total' => Discount::count(),
            'active' => Discount::where('is_active', true)->count(),
            'inactive' => Discount::where('is_active', false)->count(),
        ];
    }
}
