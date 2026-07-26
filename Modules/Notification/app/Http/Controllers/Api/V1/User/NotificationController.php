<?php

namespace Modules\Notification\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use App\Support\NotificationLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notification\Models\Notification;

class NotificationController extends Controller
{
    /**
     * Get all notifications
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Notification::where('user_id', $user->id)
            ->where('user_type', 'client')
            ->orderBy('created_at', 'desc');

        // support filtering unread notifications via `unread=1|0` or explicit `is_read`
        if ($request->has('unread')) {
            $unread = filter_var($request->query('unread'), FILTER_VALIDATE_BOOLEAN);
            // unread=true => is_read = false, unread=false => is_read = true
            $query->where('is_read', ! $unread);
        } elseif ($request->has('is_read')) {
            $query->where('is_read', $request->boolean('is_read'));
        }

        $perPage = (int) $request->query('limit', 10);
        $notifications = $query->paginate($perPage);

        $uploader = new UploadFilesService;
        $lang = $user->getNotificationLang($request->input('device_id'));

        $notifications->getCollection()->transform(function ($notification) use ($uploader, $lang) {
            return array_merge([
                'id' => $notification->id,
                'title' => NotificationLocale::fromTranslations($notification->getTranslations('title'), $lang),
                'body' => NotificationLocale::fromTranslations($notification->getTranslations('message'), $lang),
                'type' => $notification->type,
                'image' => $uploader->getFullUrl($notification->image),
                'is_read' => $notification->is_read,
                'created_at' => $notification->created_at->toISOString(),
            ], $notification->orderMetaForApi());
        });

        return successResponse($notifications, 'Notifications retrieved successfully');
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(Request $request, int $notification_id): JsonResponse
    {
        $user = $request->user();

        $notification = Notification::where('id', $notification_id)
            ->where('user_id', $user->id)
            ->where('user_type', 'client')
            ->first();

        if (! $notification) {
            return notFoundResponse('Notification not found');
        }

        $notification->update(['is_read' => true]);

        return successResponse([
            'notification_id' => $notification->id,
            'is_read' => true,
        ], 'Notification marked as read');
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();

        Notification::where('user_id', $user->id)
            ->where('user_type', 'client')
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return successResponse(null, 'All notifications marked as read');
    }

    /**
     * Get unread notifications count
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    // Removed: use /notifications?unread=true to filter unread and get paginated results
}
