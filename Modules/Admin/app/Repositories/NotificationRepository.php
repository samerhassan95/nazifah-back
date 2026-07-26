<?php

namespace Modules\Admin\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Admin\Interfaces\NotificationRepositoryInterface;
use Modules\Notification\Models\Notification;

class NotificationRepository implements NotificationRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator
    {
        $query = Notification::query();

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

    public function find(int $id): ?Notification
    {
        return Notification::find($id);
    }

    public function create(array $data): Notification
    {
        return Notification::create($data);
    }

    public function update(Notification $notification, array $data): bool
    {
        return $notification->update($data);
    }

    public function delete(Notification $notification): bool
    {
        return $notification->delete();
    }

    public function toggleStatus(Notification $notification): bool
    {
        return $notification->update(['is_active' => ! $notification->is_active]);
    }

    public function getStatistics(): array
    {
        return [
            'total' => Notification::count(),
            'active' => Notification::where('is_active', true)->count(),
            'inactive' => Notification::where('is_active', false)->count(),
        ];
    }
}
