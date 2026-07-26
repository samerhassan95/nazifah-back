<?php

namespace Modules\Client\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Client\Interfaces\ClientRepositoryInterface;
use Modules\Client\Models\Client;

class ClientService
{
    public function __construct(
        private ClientRepositoryInterface $clientRepository
    ) {}

    public function getAllClients(array $filters = []): LengthAwarePaginator
    {
        return $this->clientRepository->all($filters);
    }

    public function getClientById(int $id): ?Client
    {
        return $this->clientRepository->find($id);
    }

    public function createClient(array $data): Client
    {
        return $this->clientRepository->create($data);
    }

    public function updateClient(int $id, array $data): ?Client
    {
        $client = $this->clientRepository->find($id);

        if (! $client) {
            return null;
        }

        $this->clientRepository->update($client, $data);

        return $client->fresh();
    }

    public function deleteClient(int $id): bool
    {
        $client = $this->clientRepository->find($id);

        if (! $client) {
            return false;
        }

        return $this->clientRepository->delete($client);
    }
}
