<?php

namespace Modules\Vendor\Services;

use Modules\Vendor\Models\Vendor;
use Modules\Vendor\Models\VendorRole;

class VendorDefaultRolesService
{
    public function __construct(
        private VendorSystemRolesSyncService $syncService,
    ) {}

    public function seedForVendor(Vendor $vendor): void
    {
        $this->syncService->seedForVendor($vendor);
    }

    public function findSystemRole(int $vendorId, string $name): ?VendorRole
    {
        return VendorRole::where('vendor_id', $vendorId)
            ->where('name', $name)
            ->where('is_system', true)
            ->first();
    }
}
