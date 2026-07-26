<?php

namespace Modules\Admin\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Admin\Interfaces\PieceRepositoryInterface;
use Modules\Piece\Models\Piece;

class PieceRepository implements PieceRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $query = Piece::query();

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

    public function find(int $id): ?Piece
    {
        return Piece::find($id);
    }

    public function create(array $data): Piece
    {
        return Piece::create($data);
    }

    public function update(Piece $piece, array $data): bool
    {
        return $piece->update($data);
    }

    public function delete(Piece $piece): bool
    {
        return $piece->delete();
    }

    public function toggleStatus(Piece $piece): bool
    {
        // is_active column has been removed from pieces table
        return false;
    }

    public function getStatistics(): array
    {
        return [
            'total' => Piece::count(),
            'active' => Piece::where('is_active', true)->count(),
            'inactive' => Piece::where('is_active', false)->count(),
        ];
    }
}
