<?php

namespace Modules\BannerOffer\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\BannerOffer\Interfaces\BannerOfferRepositoryInterface;
use Modules\BannerOffer\Models\BannerOffer;

class BannerOfferService
{
    public function __construct(
        private BannerOfferRepositoryInterface $bannerOfferRepository
    ) {}

    public function getAllBannerOffers(array $filters = []): LengthAwarePaginator
    {
        return $this->bannerOfferRepository->all($filters);
    }

    public function getBannerOfferById(int $id): ?BannerOffer
    {
        return $this->bannerOfferRepository->find($id);
    }

    public function createBannerOffer(array $data): BannerOffer
    {
        return $this->bannerOfferRepository->create($data);
    }

    public function updateBannerOffer(int $id, array $data): ?BannerOffer
    {
        $bannerOffer = $this->bannerOfferRepository->find($id);

        if (! $bannerOffer) {
            return null;
        }

        $this->bannerOfferRepository->update($bannerOffer, $data);

        return $bannerOffer->fresh();
    }

    public function deleteBannerOffer(int $id): bool
    {
        $bannerOffer = $this->bannerOfferRepository->find($id);

        if (! $bannerOffer) {
            return false;
        }

        return $this->bannerOfferRepository->delete($bannerOffer);
    }
}
