<?php

namespace App\Services;

use App\Support\NotificationLocale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Modules\Notification\Models\Notification;

class UserNotificationService
{
    /**
     * FCM payloads that should use the mobile app's custom new-order sound/channel.
     */
    private const NEW_ORDER_NOTIFICATION_TYPES = [
        'new_order',
        'driver_pickup_assigned',
        'driver_delivery_assigned',
    ];

    private const NEW_ORDER_SOUND = 'new_order.wav';

    private const NEW_ORDER_ANDROID_CHANNEL = 'new_order_channel';

    public function __construct(protected FirebaseService $firebaseService) {}

    /**
     * Persist in-app notification and send FCM push to all user devices.
     *
     * @param  array<string, mixed>  $data
     */
    public function notify(
        Model $user,
        string $userType,
        string $titleAr,
        string $titleEn,
        string $bodyAr,
        string $bodyEn,
        string $type,
        array $data = [],
        ?string $image = null,
    ): void {
        $notificationId = null;

        try {
            $notification = Notification::create([
                'user_id' => $user->id,
                'user_type' => $userType,
                'title' => ['ar' => $titleAr, 'en' => $titleEn],
                'message' => ['ar' => $bodyAr, 'en' => $bodyEn],
                'type' => $type,
                'is_read' => false,
                'image' => $image,
                'data' => $data !== [] ? $data : null,
            ]);

            $notificationId = $notification->id;
        } catch (\Throwable $e) {
            $this->logWarning('Notification DB save failed', $userType, (int) $user->id, $e);
        }

        try {
            $pushData = array_merge(
                [
                    'type' => (string) ($data['notification_type'] ?? $type),
                    'notification_id' => $notificationId ? (string) $notificationId : '',
                ],
                $this->flattenForFcm($data)
            );

            $this->pushToUser($user, $titleAr, $titleEn, $bodyAr, $bodyEn, $pushData);
        } catch (\Throwable $e) {
            $this->logWarning('Notification FCM push failed', $userType, (int) $user->id, $e);
        }
    }

    /**
     * Send FCM push only (no DB row).
     *
     * @param  array<string, mixed>  $data
     */
    public function pushToUser(
        Model $user,
        string $titleAr,
        string $titleEn,
        string $bodyAr,
        string $bodyEn,
        array $data = []
    ): void {
        if (method_exists($user, 'fcmTokens')) {
            $user->loadMissing('fcmTokens');

            foreach ($user->fcmTokens as $fcmToken) {
                $this->sendPushToToken(
                    $fcmToken->token,
                    $fcmToken->lang,
                    $titleAr,
                    $titleEn,
                    $bodyAr,
                    $bodyEn,
                    $data
                );
            }
        }

        if (
            (! method_exists($user, 'fcmTokens') || $user->fcmTokens->isEmpty())
            && ! empty($user->fcm_token)
        ) {
            $lang = method_exists($user, 'getNotificationLang')
                ? $user->getNotificationLang()
                : 'ar';

            $this->sendPushToToken(
                (string) $user->fcm_token,
                $lang,
                $titleAr,
                $titleEn,
                $bodyAr,
                $bodyEn,
                $data
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sendPushToToken(
        string $token,
        ?string $lang,
        string $titleAr,
        string $titleEn,
        string $bodyAr,
        string $bodyEn,
        array $data
    ): void {
        if ($token === '') {
            return;
        }

        $notificationType = (string) ($data['notification_type'] ?? $data['type'] ?? '');
        $useNewOrderSound = $this->usesNewOrderSound($notificationType);

        $payload = [
            'title' => NotificationLocale::pick($titleAr, $titleEn, $lang),
            'body' => NotificationLocale::pick($bodyAr, $bodyEn, $lang),
            'sound' => $useNewOrderSound ? self::NEW_ORDER_SOUND : 'default',
        ];

        if ($useNewOrderSound) {
            $payload['channel_id'] = self::NEW_ORDER_ANDROID_CHANNEL;
        }

        $this->firebaseService->sendToDevice($token, $this->flattenForFcm($data), $payload);
    }

    private function usesNewOrderSound(string $notificationType): bool
    {
        return in_array($notificationType, self::NEW_ORDER_NOTIFICATION_TYPES, true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function flattenForFcm(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_scalar($value)) {
                $normalized[(string) $key] = (string) $value;

                continue;
            }

            $normalized[(string) $key] = json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
        }

        return $normalized;
    }

    private function logWarning(string $message, string $userType, int $userId, \Throwable $e): void
    {
        try {
            Log::warning($message, [
                'user_type' => $userType,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable) {
        }
    }
}
