<?php

namespace Modules\Admin\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Admin\Interfaces\BannerOfferRepositoryInterface;
use Modules\BannerOffer\Models\BannerOffer;

class BannerOfferRepository implements BannerOfferRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $query = BannerOffer::query();

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

    public function find(int $id): ?BannerOffer
    {
        return BannerOffer::find($id);
    }

    public function create(array $data): BannerOffer
    {
        return BannerOffer::create($data);
    }

    public function update(BannerOffer $banneroffer, array $data): bool
    {
        return $banneroffer->update($data);
    }

    public function delete(BannerOffer $banneroffer): bool
    {
        return $banneroffer->delete();
    }

    public function toggleStatus(BannerOffer $banneroffer): bool
    {
        return $banneroffer->update(['is_active' => ! $banneroffer->is_active]);
    }

    public function getStatistics(): array
    {
        return [
            'total' => BannerOffer::count(),
            'active' => BannerOffer::where('is_active', true)->count(),
            'inactive' => BannerOffer::where('is_active', false)->count(),
        ];
    }
}
