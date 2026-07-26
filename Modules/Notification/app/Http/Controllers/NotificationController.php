<?php

namespace Modules\Notification\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Notification\Http\Requests\StoreNotificationRequest;
use Modules\Notification\Http\Requests\UpdateNotificationRequest;
use Modules\Notification\Http\Resources\NotificationResource;
use Modules\Notification\Services\NotificationService;

class NotificationController extends Controller
{
    public function __construct(private NotificationService $notificationService) {}

    public function index(): JsonResponse
    {
        $notifications = $this->notificationService->getAllNotifications();

        return successResponse(
            NotificationResource::collection($notifications),
            'Notifications retrieved successfully'
        );
    }

    public function store(StoreNotificationRequest $request): JsonResponse
    {
        $notification = $this->notificationService->createNotification($request->validated());

        return successResponse(new NotificationResource($notification), 'Notification created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $notification = $this->notificationService->getNotificationById($id);
        if (! $notification) {
            return notFoundResponse('Notification not found');
        }

        return successResponse(new NotificationResource($notification), 'Notification retrieved successfully');
    }

    public function update(UpdateNotificationRequest $request, int $id): JsonResponse
    {
        $notification = $this->notificationService->updateNotification($id, $request->validated());
        if (! $notification) {
            return notFoundResponse('Notification not found');
        }

        return successResponse(new NotificationResource($notification), 'Notification updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->notificationService->deleteNotification($id);
        if (! $deleted) {
            return notFoundResponse('Notification not found');
        }

        return successResponse(null, 'Notification deleted successfully');
    }
}
