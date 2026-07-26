<?php

namespace Modules\Admin\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Admin\Interfaces\VendorRepositoryInterface;
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
        // Extract password before creating vendor
        $password = $data['password'] ?? null;
        unset($data['password'], $data['password_confirmation']);

        // Create the vendor
        $vendor = $this->vendorRepository->create($data);

        // Create vendor employee (owner) if password is provided
        if ($password && $vendor) {
            \Modules\Vendor\Models\VendorEmployee::create([
                'vendor_id' => $vendor->id,
                'branch_id' => null, // Owner is not tied to a specific branch
                'name' => is_array($vendor->name) ? ($vendor->name['en'] ?? $vendor->name['ar'] ?? 'Owner') : $vendor->name,
                'email' => $vendor->email,
                'phone' => $vendor->phone,
                'password' => $password, // Will be hashed automatically by the model
                'role' => 'owner',
                'is_verified' => true,
                'is_active' => true,
                'is_banned' => false,
            ]);
        }

        return $vendor;
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

    public function toggleVendorStatus(int $id): ?Vendor
    {
        $vendor = $this->vendorRepository->find($id);

        if (! $vendor) {
            return null;
        }

        $this->vendorRepository->toggleStatus($vendor);

        return $vendor->fresh();
    }

    public function getStatistics(): array
    {
        return $this->vendorRepository->getStatistics();
    }

    public function getAllPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $filters['per_page'] = $perPage;

        return $this->vendorRepository->all($filters);
    }

    // Backwards-compatible alias used by older callers
    public function create(array $data): Vendor
    {
        return $this->createVendor($data);
    }
}
