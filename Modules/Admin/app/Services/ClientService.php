<?php

namespace Modules\Admin\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Admin\Interfaces\ClientRepositoryInterface;
use Modules\Client\Models\Client;

class ClientService
{
    public function __construct(
        private ClientRepositoryInterface $clientRepository
    ) {}

    public function getAllPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->clientRepository->all($filters, $perPage);
    }

    public function getAllClients(array $filters = []): LengthAwarePaginator
    {
        return $this->clientRepository->all($filters);
    }

    public function find(int $id): ?Client
    {
        return $this->clientRepository->find($id);
    }

    public function getClientById(int $id): ?Client
    {
        return $this->clientRepository->find($id);
    }

    public function create(array $data): Client
    {
        return $this->clientRepository->create($data);
    }

    public function createClient(array $data): Client
    {
        return $this->create($data);
    }

    public function update(int $id, array $data): ?Client
    {
        $client = $this->clientRepository->find($id);

        if (! $client) {
            return null;
        }

        $this->clientRepository->update($client, $data);

        return $client->fresh();
    }

    public function delete(int $id): bool
    {
        $client = $this->clientRepository->find($id);

        if (! $client) {
            return false;
        }

        return $this->clientRepository->delete($client);
    }

    public function deleteClient(int $id): bool
    {
        return $this->delete($id);
    }

    public function toggleStatus(int $id): ?Client
    {
        $client = $this->clientRepository->find($id);

        if (! $client) {
            return null;
        }

        return $this->clientRepository->toggleStatus($client);
    }

    public function toggleClientStatus(int $id): ?Client
    {
        return $this->toggleStatus($id);
    }

    public function getStatistics(?int $zoneId = null): array
    {
        return $this->clientRepository->getStatistics($zoneId);
    }
}
