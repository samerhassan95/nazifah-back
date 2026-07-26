<?php

namespace Modules\Vendor\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Vendor\Interfaces\VendorRepositoryInterface;
use Modules\Vendor\Models\Vendor;

class VendorService
{
    public function __construct(
        private VendorRepositoryInterface $vendorRepository
    ) {}

    public function getAllVendors(array $filters = []): LengthAwarePaginator
    {
        return $this->vendorRepository->all($filters);
    }

    public function getVendorById(int $id): ?Vendor
    {
        return $this->vendorRepository->find($id);
    }

    public function createVendor(array $data): Vendor
    {
        return $this->vendorRepository->create($data);
    }

    public function updateVendor(int $id, array $data): ?Vendor
    {
        $vendor = $this->vendorRepository->find($id);

        if (! $vendor) {
            return null;
        }

        $this->vendorRepository->update($vendor, $data);

        return $vendor->fresh();
    }

    public function deleteVendor(int $id): bool
    {
        $vendor = $this->vendorRepository->find($id);

        if (! $vendor) {
            return false;
        }

        return $this->vendorRepository->delete($vendor);
    }
}
