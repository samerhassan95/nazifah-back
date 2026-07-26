<?php

namespace Modules\Vendor\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Vendor\Models\Vendor;

interface VendorRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?Vendor;

    public function create(array $data): Vendor;

    public function update(Vendor $vendor, array $data): bool;

    public function delete(Vendor $vendor): bool;
}
