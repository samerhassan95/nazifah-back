<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use App\Services\UserNotificationService;
use App\Support\NotificationLocale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notification\Models\Notification;

class AdminNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Notification::query();

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // accept either `user_type` or legacy `notifiable_type` param
        if ($request->has('user_type')) {
            $query->where('user_type', $request->user_type);
        } elseif ($request->has('notifiable_type')) {
            $query->where('user_type', $request->notifiable_type);
        }

        if ($request->has('read')) {
            if ($request->read) {
                $query->whereNotNull('read_at');
            } else {
                $query->whereNull('read_at');
            }
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $notifications = $query->paginate($request->input('per_page', 15));

        $lang = NotificationLocale::normalize(app()->getLocale());
        $notifications->getCollection()->transform(function (Notification $notification) use ($lang) {
            return array_merge([
                'id' => $notification->id,
                'user_id' => $notification->user_id,
                'user_type' => $notification->user_type,
                'type' => $notification->type,
                'title' => NotificationLocale::fromTranslations($notification->getTranslations('title'), $lang),
                'description' => NotificationLocale::fromTranslations($notification->getTranslations('message'), $lang),
                'is_read' => (bool) $notification->is_read,
                'read_at' => $notification->read_at?->toISOString(),
                'created_at' => $notification->created_at?->toISOString(),
                'data' => $notification->data,
            ], $notification->orderMetaForApi());
        });

        return successResponse(
            $notifications,
            'Notifications retrieved successfully'
        );
    }

    public function sendBulkNotification(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'recipient_type' => 'required|string|in:all,clients,vendors,drivers,owners,admins',
            'recipient_ids' => 'nullable|array',
            'recipient_ids.*' => 'integer',
            'image' => 'nullable|image|max:4096',
        ]);

        $recipients = $this->getRecipients($validated['recipient_type'], $validated['recipient_ids'] ?? []);

        $notificationCount = 0;
        // store uploaded image if exists using UploadFilesService
        $imagePath = null;
        $uploader = app(UploadFilesService::class);
        if ($request->hasFile('image')) {
            $imagePath = $uploader->uploadImage($request->file('image'), 'notifications');
        }

        $notifier = app(UserNotificationService::class);
        $title = $validated['title'];
        $body = $validated['body'];

        foreach ($recipients as $recipient) {
            $userType = $this->resolveUserType($recipient);

            $notifier->notify(
                $recipient,
                $userType,
                $title,
                $title,
                $body,
                $body,
                'admin_notification',
                [
                    'notification_type' => 'admin_notification',
                ],
                $imagePath
            );

            $notificationCount++;
        }

        return successResponse(
            ['sent_count' => $notificationCount],
            "Notification sent to {$notificationCount} recipients successfully"
        );
    }

    private function getRecipients(string $type, array $ids = [])
    {
        $query = null;

        switch ($type) {
            case 'clients':
                $query = \Modules\Client\Models\Client::query();
                break;
            case 'vendors':
                $query = \Modules\Vendor\Models\VendorEmployee::query()->active();
                break;
            case 'drivers':
                $query = \Modules\Driver\Models\Driver::query();
                break;
            case 'owners':
                $query = \Modules\Owner\Models\Owner::query();
                break;
            case 'admins':
                $query = \Modules\Admin\Models\Admin::query();
                break;
            case 'all':
                return collect()
                    ->merge(\Modules\Client\Models\Client::query()->where('is_active', true)->get())
                    ->merge(\Modules\Vendor\Models\VendorEmployee::query()->active()->get())
                    ->merge(\Modules\Driver\Models\Driver::query()->where('is_banned', false)->get())
                    ->merge(\Modules\Owner\Models\Owner::query()->get())
                    ->merge(\Modules\Admin\Models\Admin::query()->get());
        }

        if ($query === null) {
            return collect();
        }

        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        }

        return $query->get();
    }

    private function resolveUserType(Model $recipient): string
    {
        return match ($recipient::class) {
            \Modules\Client\Models\Client::class => 'client',
            \Modules\Vendor\Models\VendorEmployee::class => 'vendor',
            \Modules\Driver\Models\Driver::class => 'driver',
            \Modules\Admin\Models\Admin::class => 'admin',
            \Modules\Owner\Models\Owner::class => 'owner',
            default => strtolower(class_basename($recipient)),
        };
    }

    public function show(int $id): JsonResponse
    {
        $notification = Notification::find($id);

        if (! $notification) {
            return notFoundResponse('Notification not found');
        }

        return successResponse(array_merge([
            'id' => $notification->id,
            'user_id' => $notification->user_id,
            'user_type' => $notification->user_type,
            'type' => $notification->type,
            'title' => $notification->title,
            'message' => $notification->message,
            'is_read' => (bool) $notification->is_read,
            'read_at' => $notification->read_at?->toISOString(),
            'created_at' => $notification->created_at?->toISOString(),
            'data' => $notification->data,
        ], $notification->orderMetaForApi()), 'Notification retrieved successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $notification = Notification::find($id);

        if (! $notification) {
            return notFoundResponse('Notification not found');
        }

        $notification->delete();

        return successResponse(
            null,
            'Notification deleted successfully'
        );
    }

    public function statistics(): JsonResponse
    {
        $stats = [
            'total_notifications' => Notification::count(),
            'read_notifications' => Notification::whereNotNull('read_at')->count(),
            'unread_notifications' => Notification::whereNull('read_at')->count(),
            'recent_notifications' => Notification::where('created_at', '>=', now()->subDays(7))->count(),
        ];

        return successResponse(
            $stats,
            'Notification statistics retrieved successfully'
        );
    }
}
