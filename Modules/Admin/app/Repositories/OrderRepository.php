<?php

namespace Modules\Admin\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Admin\Interfaces\OrderRepositoryInterface;
use Modules\Order\Models\Order;

class OrderRepository implements OrderRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $query = Order::with(['client', 'branch.vendor', 'driver', 'items', 'latestPayment']);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['payment_status'])) {
            $query->whereHas('paymentTransactions', function ($q) use ($filters) {
                $q->where('status', $filters['payment_status']);
            });
        }

        if (isset($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }

        if (isset($filters['vendor_id'])) {
            // Filter by vendor through branch
            $query->whereHas('branch', function ($q) use ($filters) {
                $q->where('vendor_id', $filters['vendor_id']);
            });
        }

        if (isset($filters['branch_ids']) && ! empty($filters['branch_ids'])) {
            $query->whereIn('branch_id', $filters['branch_ids']);
        }

        if (isset($filters['driver_id'])) {
            $query->where('driver_id', $filters['driver_id']);
        }

        if (isset($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('client', function ($clientQuery) use ($search) {
                        $clientQuery->where('full_name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    })
                    ->orWhereHas('branch.vendor', function ($vendorQuery) use ($search) {
                        $vendorQuery->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        return $query->paginate($filters['per_page'] ?? 15);
    }

    public function find(int $id): ?Order
    {
        return Order::with(['client', 'branch.vendor', 'driver', 'items', 'latestPayment'])->find($id);
    }

    public function create(array $data): Order
    {
        return Order::create($data);
    }

    public function update(Order $order, array $data): bool
    {
        return $order->update($data);
    }

    public function delete(Order $order): bool
    {
        return $order->delete();
    }

    public function toggleStatus(Order $order): bool
    {
        return $order->update(['is_active' => ! $order->is_active]);
    }

    public function getStatistics(): array
    {
        return [
            'total' => Order::count(),
            'active' => Order::where('is_active', true)->count(),
            'inactive' => Order::where('is_active', false)->count(),
        ];
    }
}
