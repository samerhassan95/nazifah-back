<?php

namespace Modules\Admin\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\BannerOffer\Models\BannerOffer;

interface BannerOfferRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?BannerOffer;

    public function create(array $data): BannerOffer;

    public function update(BannerOffer $banneroffer, array $data): bool;

    public function delete(BannerOffer $banneroffer): bool;

    public function toggleStatus(BannerOffer $banneroffer): bool;

    public function getStatistics(): array;
}
