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
        return $this->adRepository->all($filters);
    }

    public function getAdById(int $id): ?Ad
    {
        return $this->adRepository->find($id);
    }

    public function createAd(array $data): Ad
    {
        return $this->adRepository->create($data);
    }

    public function updateAd(int $id, array $data): ?Ad
    {
        $ad = $this->adRepository->find($id);

        if (! $ad) {
            return null;
        }

        $this->adRepository->update($ad, $data);

        return $ad->fresh();
    }

    public function deleteAd(int $id): bool
    {
        $ad = $this->adRepository->find($id);

        if (! $ad) {
            return false;
        }

        return $this->adRepository->delete($ad);
    }

    public function toggleAdStatus(int $id): ?Ad
    {
        $ad = $this->adRepository->find($id);

        if (! $ad) {
            return null;
        }

        $this->adRepository->toggleStatus($ad);

        return $ad->fresh();
    }

    public function getStatistics(): array
    {
        return $this->adRepository->getStatistics();
    }
}
