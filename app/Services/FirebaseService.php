<?php

namespace App\Services;

/**
 * Backward-compatible FCM facade.
 * Delegates to FirebaseAdminService (FCM HTTP v1 + service account).
 */
class FirebaseService
{
    public function __construct(protected FirebaseAdminService $admin) {}

    /**
     * @param  array<string, scalar|null>  $data
     * @param  array<string, mixed>|null  $notification
     */
    public function sendToDevice(string $deviceToken, array $data, ?array $notification = null): bool
    {
        if ($deviceToken === '') {
            return false;
        }

        $notificationPayload = $notification ?? [
            'title' => (string) ($data['title'] ?? 'Notification'),
            'body' => (string) ($data['body'] ?? ''),
            'sound' => 'default',
        ];

        return $this->admin->sendToDevice(
            $deviceToken,
            $notificationPayload,
            $this->stringifyData($data)
        );
    }

    /**
     * @param  array<int, string>  $deviceTokens
     * @param  array<string, scalar|null>  $data
     * @param  array<string, mixed>|null  $notification
     */
    public function sendToMultipleDevices(array $deviceTokens, array $data, ?array $notification = null): bool
    {
        $notificationPayload = $notification ?? [
            'title' => (string) ($data['title'] ?? 'Notification'),
            'body' => (string) ($data['body'] ?? ''),
            'sound' => 'default',
        ];

        $stringData = $this->stringifyData($data);
        $success = false;

        foreach ($deviceTokens as $token) {
            if ($this->admin->sendToDevice($token, $notificationPayload, $stringData)) {
                $success = true;
            }
        }

        return $success;
    }

    /**
     * @param  array<string, scalar|null>  $data
     * @param  array<string, mixed>|null  $notification
     */
    public function sendToTopic(string $topic, array $data, ?array $notification = null): bool
    {
        $notificationPayload = $notification ?? [
            'title' => (string) ($data['title'] ?? 'Notification'),
            'body' => (string) ($data['body'] ?? ''),
            'sound' => 'default',
        ];

        return $this->admin->sendToTopic($topic, $notificationPayload, $this->stringifyData($data));
    }

    public function sendChatNotification(string $deviceToken, array $messageData): bool
    {
        return $this->admin->sendChatNotification($deviceToken, $messageData);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function stringifyData(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            $normalized[(string) $key] = is_scalar($value) ? (string) $value : json_encode($value);
        }

        return $normalized;
    }
}
