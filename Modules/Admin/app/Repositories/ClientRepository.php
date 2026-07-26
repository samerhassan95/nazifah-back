<?php

namespace Modules\Admin\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Admin\Interfaces\ClientRepositoryInterface;
use Modules\Client\Models\Client;

class ClientRepository implements ClientRepositoryInterface
{
    public function all(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Client::with('addresses');

        if (isset($filters['is_verified'])) {
            $query->where('is_verified', $filters['is_verified']);
        }

        if (isset($filters['is_banned'])) {
            $query->where('is_banned', $filters['is_banned']);
        }

        if (isset($filters['zone_id'])) {
            $query->whereHas('addresses', function ($q) use ($filters) {
                $q->where('zone_id', $filters['zone_id']);
            });
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%");
            });
        }

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        return $query->paginate($perPage);
    }

    public function find(int $id): ?Client
    {
        return Client::find($id);
    }

    public function create(array $data): Client
    {
        return Client::create($data);
    }

    public function update(Client $client, array $data): bool
    {
        return $client->update($data);
    }

    public function delete(Client $client): bool
    {
        return $client->delete();
    }

    public function toggleStatus(Client $client): Client
    {
        $client->update(['is_verified' => ! $client->is_verified]);

        return $client->fresh();
    }

    public function getStatistics(?int $zoneId = null): array
    {
        $query = Client::query();

        // Filter by zone_id if provided
        if ($zoneId) {
            $query->whereHas('addresses', function ($q) use ($zoneId) {
                $q->where('zone_id', $zoneId);
            });
        }

        $totalClients = (clone $query)->count();
        $newClientsMonthly = (clone $query)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        // Calculate average orders per client
        $clientIds = (clone $query)->pluck('id');
        $totalOrders = \Modules\Order\Models\Order::whereIn('client_id', $clientIds)->count();
        $orderAverage = $totalClients > 0 ? round($totalOrders / $totalClients, 2) : 0;

        $bannedClients = (clone $query)->where('is_banned', true)->count();

        return [
            'Total_clients' => $totalClients,
            'new_clients_monthly' => $newClientsMonthly,
            'Order_average' => $orderAverage,
            'Banned_clients' => $bannedClients,
        ];
    }
}
