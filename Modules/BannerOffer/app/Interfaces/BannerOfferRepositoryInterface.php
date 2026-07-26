<?php

namespace Modules\BannerOffer\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\BannerOffer\Models\BannerOffer;

interface BannerOfferRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?BannerOffer;

    public function create(array $data): BannerOffer;

    public function update(BannerOffer $bannerOffer, array $data): bool;

    public function delete(BannerOffer $bannerOffer): bool;
}
