<?php

namespace Modules\Admin\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Address\Models\Address;

interface AddressRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?Address;

    public function create(array $data): Address;

    public function update(Address $address, array $data): bool;

    public function delete(Address $address): bool;

    public function toggleStatus(Address $address): bool;

    public function getStatistics(): array;
}
