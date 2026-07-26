<?php

namespace Modules\Notification\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\UserNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notification\Enums\UserTargetType;
use Modules\Notification\Http\Requests\Admin\StoreMarketingNotificationRequest;
use Modules\Notification\Http\Resources\Admin\MarketingNotificationResource;
use Modules\Notification\Models\MarketingNotification;
use Modules\Notification\Models\NotificationLog;

class MarketingNotificationController extends Controller
{
    public function __construct(protected UserNotificationService $userNotificationService) {}

    /**
     * Get overall marketing notification statistics
     * GET /api/v1/admin/marketing/notifications/stats
     */
    public function stats(): JsonResponse
    {
        $totalSent = MarketingNotification::whereIn('status', ['sent', 'sending'])->count();
        $totalRead = MarketingNotification::sum('read_count');
        $totalSentCount = MarketingNotification::sum('sent_count');

        $readRate = 0;
        if ($totalSentCount > 0) {
            $readRate = round(($totalRead / $totalSentCount) * 100, 2);
        }

        return successResponse([
            'total_notifications' => MarketingNotification::count(),
            'total_sent' => $totalSent,
            'total_read' => $totalRead,
            'read_rate' => $readRate.'%',
        ], 'Notification statistics retrieved successfully');
    }

    /**
     * Get today's marketing notifications
     * GET /api/v1/admin/marketing/notifications
     */
    public function index(Request $request): JsonResponse
    {
        $todayNotifications = MarketingNotification::whereDate('scheduled_at', today())
            ->orWhereDate('sending_date', today())
            ->orderBy('created_at', 'desc')
            ->get();

        return successResponse([
            'todays_notifications' => MarketingNotificationResource::collection($todayNotifications),
        ], 'Today\'s notifications retrieved successfully');
    }

    /**
     * Get all marketing notifications with pagination
     * GET /api/v1/admin/marketing/notifications/all
     */
    public function all(Request $request): JsonResponse
    {
        $perPage = $request->query('per_page', 15);
        $status = $request->query('status');
        $targetType = $request->query('target_type');

        $query = MarketingNotification::query()->orderBy('created_at', 'desc');

        if ($status) {
            $query->where('status', $status);
        }
        if ($targetType) {
            $query->where('user_target_type', $targetType);
        }

        $notifications = $query->paginate($perPage);

        return successResponse([
            'notifications' => MarketingNotificationResource::collection($notifications),
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
        ], 'Marketing notifications retrieved successfully');
    }

    /**
     * Create new marketing notification
     * POST /api/v1/admin/marketing/notification_management
     */
    public function store(StoreMarketingNotificationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $admin = $request->user();

        $scheduledAt = $validated['scheduled_at'] ?? now();

        $marketingNotification = MarketingNotification::create([
            'notification_title' => $validated['title'],
            'description' => $validated['body'],
            'user_target_type' => $validated['target_type'],
            'target_user_ids' => $validated['target_ids'] ?? null,
            'sending_date' => \Carbon\Carbon::parse($scheduledAt)->format('Y-m-d'),
            'sending_time' => \Carbon\Carbon::parse($scheduledAt)->format('H:i:s'),
            'scheduled_at' => $scheduledAt,
            'deep_link' => $validated['deep_link'] ?? null,
            'image_url' => $validated['image_url'] ?? null,
            'segment_filters' => $validated['segment_filters'] ?? null,
            'created_by' => $admin->id,
            'status' => 'draft',
        ]);

        return successResponse([
            'notification' => new MarketingNotificationResource($marketingNotification),
        ], 'Marketing notification created successfully', 201);
    }

    /**
     * Send marketing notification to target users
     * POST /api/v1/admin/marketing/notifications/{id}/send
     */
    public function send(int $id): JsonResponse
    {
        $notification = MarketingNotification::find($id);

        if (! $notification) {
            return notFoundResponse('Marketing notification not found');
        }

        $notification->update(['status' => 'sending', 'sent_at' => now()]);

        // In a real scenario, this would dispatch a job.
        // For now, we will just call the internal method.
        $this->sendMarketingNotification($notification);

        return successResponse([
            'notification' => new MarketingNotificationResource($notification->fresh()),
        ], 'Marketing notification is being sent');
    }

    /**
     * Resend marketing notification to failed targets
     * POST /api/v1/admin/marketing/notifications/{id}/resend
     */
    public function resend(int $id): JsonResponse
    {
        $notification = MarketingNotification::find($id);

        if (! $notification) {
            return notFoundResponse('Marketing notification not found');
        }

        if ($notification->status !== 'failed') {
            return errorResponse('Only failed notifications can be resent', 400);
        }

        $notification->update(['status' => 'sending']);

        // In a real scenario, dispatch a job to only retry failed logs.
        $this->sendMarketingNotification($notification);

        return successResponse([
            'notification' => new MarketingNotificationResource($notification->fresh()),
        ], 'Marketing notification resend initiated');
    }

    /**
     * Send marketing notification to target users (Internal)
     */
    private function sendMarketingNotification(MarketingNotification $marketingNotification): void
    {
        try {
            $targetUsers = collect();
            // Mock fetching users based on target type
            if ($marketingNotification->user_target_type === 'all' || $marketingNotification->user_target_type === UserTargetType::ALL->value) {
                $targetUsers = \Modules\Client\Models\Client::where('is_active', true)->get();
            } else {
                $targetUsers = \Modules\Client\Models\Client::where('is_active', true)->take(5)->get(); // Mock logic
            }

            $total = $targetUsers->count();
            $marketingNotification->update(['total_recipients' => $total]);

            $sent = 0;
            $failed = 0;

            foreach ($targetUsers as $user) {
                try {
                    // Create NotificationLog
                    NotificationLog::create([
                        'marketing_notification_id' => $marketingNotification->id,
                        'user_id' => $user->id,
                        'status' => 'sent',
                    ]);

                    $title = (string) $marketingNotification->notification_title;
                    $body = (string) $marketingNotification->description;

                    $this->userNotificationService->notify(
                        $user,
                        'client',
                        $title,
                        $title,
                        $body,
                        $body,
                        'marketing',
                        [
                            'notification_type' => 'marketing',
                            'deep_link' => $marketingNotification->deep_link,
                            'image_url' => $marketingNotification->image_url,
                        ]
                    );

                    $sent++;
                } catch (\Exception $e) {
                    $failed++;
                    NotificationLog::create([
                        'marketing_notification_id' => $marketingNotification->id,
                        'user_id' => $user->id,
                        'status' => 'failed',
                        'failure_reason' => $e->getMessage(),
                    ]);
                }
            }

            $status = $failed > 0 ? 'failed' : 'sent';

            $marketingNotification->update([
                'status' => $status,
                'sent_count' => $sent,
                'failed_count' => $failed,
                'is_sent' => true,
                'sent_at' => now(),
            ]);

        } catch (\Exception $e) {
            $marketingNotification->update(['status' => 'failed']);
        }
    }

    /**
     * Delete marketing notification
     * DELETE /api/v1/admin/marketing/notifications/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $notification = MarketingNotification::find($id);

        if (! $notification) {
            return notFoundResponse('Marketing notification not found');
        }

        $notification->delete();

        return successResponse(null, 'Marketing notification deleted successfully');
    }
}
