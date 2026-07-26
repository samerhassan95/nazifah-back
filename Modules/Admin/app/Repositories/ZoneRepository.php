<?php

namespace Modules\Admin\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Address\Models\Address;
use Modules\Admin\Interfaces\ZoneRepositoryInterface;
use Modules\Branch\Models\Branch;
use Modules\Order\Models\Order;
use Modules\Zone\Models\Zone;

class ZoneRepository implements ZoneRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $query = Zone::query()
            ->withCount(['branches', 'addresses'])
            ->addSelect([
                'clients_count' => Address::query()
                    ->selectRaw('COUNT(DISTINCT client_id)')
                    ->whereColumn('zone_id', 'zones.id')
                    ->whereNotNull('client_id'),
                'orders_count' => Order::query()
                    ->join('addresses', 'orders.delivery_address_id', '=', 'addresses.id')
                    ->selectRaw('COUNT(orders.id)')
                    ->whereColumn('addresses.zone_id', 'zones.id'),
            ]);

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        return $query->paginate($filters['per_page'] ?? 15);
    }

    public function find(int $id): ?Zone
    {
        return Zone::find($id);
    }

    public function create(array $data): Zone
    {
        if (isset($data['points'])) {
            $data['points'] = $this->normalizePoints($data['points']);
        }

        return Zone::create($data);
    }

    public function update(Zone $zone, array $data): bool
    {
        if (isset($data['points'])) {
            $data['points'] = $this->normalizePoints($data['points']);
        }

        return $zone->update($data);
    }

    public function delete(Zone $zone): bool
    {
        return $zone->delete();
    }

    public function toggleStatus(Zone $zone): bool
    {
        return $zone->update(['is_active' => ! $zone->is_active]);
    }

    public function getStatistics(): array
    {
        $locale = app()->getLocale();

        $ordersByZone = Zone::query()
            ->leftJoinSub(
                DB::table('orders')
                    ->join('addresses', 'orders.delivery_address_id', '=', 'addresses.id')
                    ->select('addresses.zone_id', DB::raw('COUNT(orders.id) as orders_count'))
                    ->groupBy('addresses.zone_id'),
                'zone_orders',
                fn ($join) => $join->on('zones.id', '=', 'zone_orders.zone_id')
            )
            ->select('zones.id', 'zones.name', DB::raw('COALESCE(zone_orders.orders_count, 0) as orders_count'));

        $topZone = (clone $ordersByZone)
            ->orderByDesc('orders_count')
            ->orderBy('zones.id')
            ->first();

        $leastZone = (clone $ordersByZone)
            ->where('zones.is_active', true)
            ->orderBy('orders_count')
            ->orderBy('zones.id')
            ->first();

        return [
            'total' => Zone::count(),
            'active' => Zone::where('is_active', true)->count(),
            'inactive' => Zone::where('is_active', false)->count(),
            'total_branches' => Branch::count(),
            'total_clients' => Address::query()->distinct('client_id')->whereNotNull('client_id')->count('client_id'),
            'total_orders' => Order::count(),
            'top_zone_by_orders' => $topZone ? [
                'id' => $topZone->id,
                'name' => data_get(json_decode($topZone->name, true), $locale, $topZone->name),
                'orders_count' => (int) $topZone->orders_count,
            ] : null,
            'least_active_zone_by_orders' => $leastZone ? [
                'id' => $leastZone->id,
                'name' => data_get(json_decode($leastZone->name, true), $locale, $leastZone->name),
                'orders_count' => (int) $leastZone->orders_count,
            ] : null,
        ];
    }

    private function normalizePoints(mixed $points): array
    {
        if (is_string($points)) {
            $points = json_decode($points, true);
        } elseif (is_object($points)) {
            $points = (array) $points;
        }

        if (! is_array($points)) {
            return [];
        }

        $normalized = [];
        foreach ($points as $point) {
            if (! is_array($point) && ! is_object($point)) {
                continue;
            }

            $pointArr = (array) $point;
            $latitude = $pointArr['latitude'] ?? $pointArr['lat'] ?? $pointArr[1] ?? null;
            $longitude = $pointArr['longitude'] ?? $pointArr['lng'] ?? $pointArr['long'] ?? $pointArr[0] ?? null;

            if ($latitude === null || $longitude === null) {
                continue;
            }

            $normalized[] = [
                'latitude' => (float) $latitude,
                'longitude' => (float) $longitude,
            ];
        }

        return $normalized;
    }
}
