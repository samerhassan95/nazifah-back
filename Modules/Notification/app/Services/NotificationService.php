<?php

namespace Modules\Notification\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Notification\Interfaces\NotificationRepositoryInterface;
use Modules\Notification\Models\Notification;

class NotificationService
{
    public function __construct(
        private NotificationRepositoryInterface $notificationRepository
    ) {}

    public function getAllNotifications(array $filters = []): LengthAwarePaginator
    {
        return $this->notificationRepository->all($filters);
    }

    public function getNotificationById(int $id): ?Notification
    {
        return $this->notificationRepository->find($id);
    }

    public function createNotification(array $data): Notification
    {
        return $this->notificationRepository->create($data);
    }

    public function updateNotification(int $id, array $data): ?Notification
    {
        $notification = $this->notificationRepository->find($id);

        if (! $notification) {
            return null;
        }

        $this->notificationRepository->update($notification, $data);

        return $notification->fresh();
    }

    public function deleteNotification(int $id): bool
    {
        $notification = $this->notificationRepository->find($id);

        if (! $notification) {
            return false;
        }

        return $this->notificationRepository->delete($notification);
    }
}
