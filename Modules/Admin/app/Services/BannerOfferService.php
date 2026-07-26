<?php

namespace Modules\Admin\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Admin\Interfaces\BannerOfferRepositoryInterface;
use Modules\BannerOffer\Models\BannerOffer;

class BannerOfferService
{
    public function __construct(
        private BannerOfferRepositoryInterface $bannerofferRepository
    ) {}

    public function getAllBannerOffers(array $filters = []): LengthAwarePaginator
    {
        return $this->Repository->all($filters);
    }

    public function getBannerOfferById(int $id): ?BannerOffer
    {
        return $this->Repository->find($id);
    }

    public function createBannerOffer(array $data): BannerOffer
    {
        return $this->Repository->create($data);
    }

    public function updateBannerOffer(int $id, array $data): ?BannerOffer
    {
        $banneroffer = $this->Repository->find($id);

        if (! $banneroffer) {
            return null;
        }

        $this->Repository->update($banneroffer, $data);

        return $banneroffer->fresh();
    }

    public function deleteBannerOffer(int $id): bool
    {
        $banneroffer = $this->Repository->find($id);

        if (! $banneroffer) {
            return false;
        }

        return $this->Repository->delete($banneroffer);
    }

    public function toggleBannerOfferStatus(int $id): ?BannerOffer
    {
        $banneroffer = $this->Repository->find($id);

        if (! $banneroffer) {
            return null;
        }

        $this->Repository->toggleStatus($banneroffer);

        return $banneroffer->fresh();
    }

    public function getStatistics(): array
    {
        return $this->Repository->getStatistics();
    }
}
