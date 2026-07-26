<?php

namespace Modules\Admin\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Ad\Models\Ad;
use Modules\Admin\Interfaces\AdRepositoryInterface;

class AdService
{
    public function __construct(
        private AdRepositoryInterface $adRepository
    ) {}

    public function getAllAds(array $filters = []): LengthAwarePaginator
    {
        return $this->Repository->all($filters);
    }

    public function getAdById(int $id): ?Ad
    {
        return $this->Repository->find($id);
    }

    public function createAd(array $data): Ad
    {
        return $this->Repository->create($data);
    }

    public function updateAd(int $id, array $data): ?Ad
    {
        $ad = $this->Repository->find($id);

        if (! $ad) {
            return null;
        }

        $this->Repository->update($ad, $data);

        return $ad->fresh();
    }

    public function deleteAd(int $id): bool
    {
        $ad = $this->Repository->find($id);

        if (! $ad) {
            return false;
        }

        return $this->Repository->delete($ad);
    }

    public function toggleAdStatus(int $id): ?Ad
    {
        $ad = $this->Repository->find($id);

        if (! $ad) {
            return null;
        }

        $this->Repository->toggleStatus($ad);

        return $ad->fresh();
    }

    public function getStatistics(): array
    {
        return $this->Repository->getStatistics();
    }
}
