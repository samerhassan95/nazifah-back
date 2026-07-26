<?php

namespace Modules\Notification\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\NotificationLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notification\Enums\NotificationType;
use Modules\Notification\Models\Notification;

class NotificationController extends Controller
{
    /**
     * Get notifications grouped by type
     * GET /api/v1/admin/notifications or /api/v1/vendor/notifications
     * Query params: ?type=system|orders|finances&per_page=15
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userType = $this->getUserType($request);

        // Build query
        $query = Notification::forUser($user->id, $userType)
            ->orderBy('created_at', 'desc');

        // Filter by type if provided and valid
        if ($request->filled('type')) {
            $type = NotificationType::tryFrom($request->type);
            if ($type) {
                $query->where('notification_type', $type->value);
            }
        }

        // Paginate
        $perPage = $request->per_page ?? 15;
        $notifications = $query->paginate($perPage);
        $lang = method_exists($user, 'getNotificationLang')
            ? $user->getNotificationLang($request->input('device_id'))
            : NotificationLocale::normalize(app()->getLocale());

        // Transform notifications
        $data = $notifications->getCollection()->transform(function ($notification) use ($lang) {
            return array_merge([
                'id' => $notification->id,
                'type' => $notification->notification_type instanceof NotificationType
                    ? $notification->notification_type->value
                    : ($notification->notification_type ?? $notification->type ?? 'system'),
                'title' => NotificationLocale::fromTranslations($notification->getTranslations('title'), $lang),
                'description' => NotificationLocale::fromTranslations($notification->getTranslations('message'), $lang),
                'time' => $notification->created_at->toISOString(),
                'is_read' => (bool) $notification->is_read,
                'read_at' => $notification->read_at?->toISOString(),
                'data' => $notification->data,
            ], $notification->orderMetaForApi());
        });

        return successResponse(
            $data,
            'Notifications retrieved successfully',
            200,
            [
                'current_page' => $notifications->currentPage(),
                'from' => $notifications->firstItem(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'to' => $notifications->lastItem(),
                'total' => $notifications->total(),
            ]
        );
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $userType = $this->getUserType($request);

        $notification = Notification::forUser($user->id, $userType)->find($id);

        if (! $notification) {
            return notFoundResponse('Notification not found');
        }

        $notification->markAsRead();

        return successResponse(null, 'Notification marked as read');
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $userType = $this->getUserType($request);

        Notification::forUser($user->id, $userType)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return successResponse(null, 'All notifications marked as read');
    }

    /**
     * Get unread count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        $userType = $this->getUserType($request);

        $count = Notification::forUser($user->id, $userType)
            ->where('is_read', false)
            ->count();

        return successResponse(['unread_count' => $count], 'Unread count retrieved');
    }

    /**
     * Get single notification
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $userType = $this->getUserType($request);

        $notification = Notification::forUser($user->id, $userType)->find($id);

        if (! $notification) {
            return notFoundResponse('Notification not found');
        }

        return successResponse(array_merge([
            'id' => $notification->id,
            'title' => $notification->title,
            'description' => $notification->message,
            'time' => $notification->created_at->toISOString(),
            'is_read' => $notification->is_read,
            'read_at' => $notification->read_at?->toISOString(),
            'type' => $notification->type,
            'data' => $notification->data,
        ], $notification->orderMetaForApi()), 'Notification retrieved successfully');
    }

    /**
     * Delete single notification
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $userType = $this->getUserType($request);

        $notification = Notification::forUser($user->id, $userType)->find($id);

        if (! $notification) {
            return notFoundResponse('Notification not found');
        }

        $notification->delete();

        return successResponse(null, 'Notification deleted successfully');
    }

    /**
     * Delete all notifications
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $userType = $this->getUserType($request);

        $deleted = Notification::forUser($user->id, $userType)->delete();

        return successResponse([
            'deleted_count' => $deleted,
        ], 'All notifications deleted successfully');
    }

    /**
     * Determine user type from request guard
     */
    private function getUserType(Request $request): string
    {
        // Check which guard authenticated the user
        if ($request->user('admin')) {
            return 'admin';
        } elseif ($request->user('vendor')) {
            return 'vendor';
        } elseif ($request->user('driver')) {
            return 'driver';
        } elseif ($request->user('client')) {
            return 'client';
        }

        return 'admin'; // default
    }
}
