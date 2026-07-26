<?php

namespace Modules\Admin\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Admin\Interfaces\DiscountRepositoryInterface;
use Modules\Discount\Models\Discount;

class DiscountService
{
    public function __construct(
        private DiscountRepositoryInterface $discountRepository
    ) {}

    public function getAllDiscounts(array $filters = []): LengthAwarePaginator
    {
        return $this->Repository->all($filters);
    }

    public function getDiscountById(int $id): ?Discount
    {
        return $this->Repository->find($id);
    }

    public function createDiscount(array $data): Discount
    {
        return $this->Repository->create($data);
    }

    public function updateDiscount(int $id, array $data): ?Discount
    {
        $discount = $this->Repository->find($id);

        if (! $discount) {
            return null;
        }

        $this->Repository->update($discount, $data);

        return $discount->fresh();
    }

    public function deleteDiscount(int $id): bool
    {
        $discount = $this->Repository->find($id);

        if (! $discount) {
            return false;
        }

        return $this->Repository->delete($discount);
    }

    public function toggleDiscountStatus(int $id): ?Discount
    {
        $discount = $this->Repository->find($id);

        if (! $discount) {
            return null;
        }

        $this->Repository->toggleStatus($discount);

        return $discount->fresh();
    }

    public function getStatistics(): array
    {
        return $this->Repository->getStatistics();
    }
}
