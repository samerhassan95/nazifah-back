<?php

namespace Modules\Address\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Address\Interfaces\AddressRepositoryInterface;
use Modules\Address\Models\Address;

class AddressService
{
    public function __construct(
        private AddressRepositoryInterface $addressRepository
    ) {}

    public function getAllAddresses(array $filters = []): LengthAwarePaginator
    {
        return $this->addressRepository->all($filters);
    }

    public function getAddressById(int $id): ?Address
    {
        return $this->addressRepository->find($id);
    }

    public function createAddress(array $data): Address
    {
        return $this->addressRepository->create($data);
    }

    public function updateAddress(int $id, array $data): ?Address
    {
        $address = $this->addressRepository->find($id);

        if (! $address) {
            return null;
        }

        $this->addressRepository->update($address, $data);

        return $address->fresh();
    }

    public function deleteAddress(int $id): bool
    {
        $address = $this->addressRepository->find($id);

        if (! $address) {
            return false;
        }

        return $this->addressRepository->delete($address);
    }
}
