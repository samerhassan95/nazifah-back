<?php

namespace Modules\Admin\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Notification\Models\Notification;

interface NotificationRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?Notification;

    public function create(array $data): Notification;

    public function update(Notification $notification, array $data): bool;

    public function delete(Notification $notification): bool;

    public function toggleStatus(Notification $notification): bool;

    public function getStatistics(): array;
}
