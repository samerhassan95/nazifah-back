<?php

namespace Modules\Admin\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Admin\Interfaces\NotificationRepositoryInterface;
use Modules\Notification\Models\Notification;

class NotificationService
{
    public function __construct(
        private NotificationRepositoryInterface $notificationRepository
    ) {}

    public function getAllNotifications(array $filters = []): LengthAwarePaginator
    {
        return $this->Repository->all($filters);
    }

    public function getNotificationById(int $id): ?Notification
    {
        return $this->Repository->find($id);
    }

    public function createNotification(array $data): Notification
    {
        return $this->Repository->create($data);
    }

    public function updateNotification(int $id, array $data): ?Notification
    {
        $notification = $this->Repository->find($id);

        if (! $notification) {
            return null;
        }

        $this->Repository->update($notification, $data);

        return $notification->fresh();
    }

    public function deleteNotification(int $id): bool
    {
        $notification = $this->Repository->find($id);

        if (! $notification) {
            return false;
        }

        return $this->Repository->delete($notification);
    }

    public function toggleNotificationStatus(int $id): ?Notification
    {
        $notification = $this->Repository->find($id);

        if (! $notification) {
            return null;
        }

        $this->Repository->toggleStatus($notification);

        return $notification->fresh();
    }

    public function getStatistics(): array
    {
        return $this->Repository->getStatistics();
    }
}
