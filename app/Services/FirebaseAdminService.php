<?php

namespace App\Services;

use App\Models\FcmToken;
use Google\Auth\Credentials\ServiceAccountCredentials;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Log;

class FirebaseAdminService
{
    protected string $projectId;

    protected string $serviceAccountPath;

    protected ?string $accessToken = null;

    protected ?int $tokenExpiry = null;

    public function __construct()
    {
        $this->serviceAccountPath = $this->resolveServiceAccountPath();
        $this->projectId = (string) (config('services.firebase.project_id')
            ?: $this->readProjectIdFromServiceAccount()
            ?: '');
    }

    public function isConfigured(): bool
    {
        return $this->projectId !== ''
            && $this->serviceAccountPath !== ''
            && is_readable($this->serviceAccountPath);
    }

    protected function getAccessToken(): string
    {
        if ($this->accessToken && $this->tokenExpiry && time() < $this->tokenExpiry) {
            return $this->accessToken;
        }

        if (! $this->isConfigured()) {
            throw new \RuntimeException('Firebase is not configured (missing project id or service account file).');
        }

        $credentials = new ServiceAccountCredentials(
            'https://www.googleapis.com/auth/firebase.messaging',
            json_decode((string) file_get_contents($this->serviceAccountPath), true)
        );

        $token = $credentials->fetchAuthToken();

        $this->accessToken = $token['access_token'];
        $this->tokenExpiry = time() + ($token['expires_in'] ?? 3600) - 300;

        return $this->accessToken;
    }

    /**
     * @param  array<string, mixed>  $notification
     * @param  array<string, string>|null  $data
     */
    public function sendToDevice(string $deviceToken, array $notification, ?array $data = null): bool
    {
        if ($deviceToken === '' || ! $this->isConfigured()) {
            return false;
        }

        try {
            $accessToken = $this->getAccessToken();

            $message = [
                'token' => $deviceToken,
                'notification' => [
                    'title' => (string) ($notification['title'] ?? ''),
                    'body' => (string) ($notification['body'] ?? ''),
                ],
            ];

            if ($data !== null && $data !== []) {
                $message['data'] = $this->stringifyData($data);
            }

            if (isset($notification['sound']) || isset($notification['badge']) || isset($notification['channel_id'])) {
                $androidNotification = [
                    'sound' => (string) ($notification['sound'] ?? 'default'),
                    'channel_id' => (string) ($notification['channel_id'] ?? 'default'),
                ];

                $message['android'] = [
                    'priority' => 'HIGH',
                    'notification' => $androidNotification,
                ];
            }

            if (isset($notification['badge']) || isset($notification['sound'])) {
                $message['apns'] = [
                    'payload' => [
                        'aps' => [
                            'sound' => (string) ($notification['sound'] ?? 'default'),
                        ],
                    ],
                ];

                if (isset($notification['badge'])) {
                    $message['apns']['payload']['aps']['badge'] = (int) $notification['badge'];
                }
            }

            $client = new Client;
            $response = $client->post(
                "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send",
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$accessToken,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'message' => $message,
                    ],
                ]
            );

            return $response->getStatusCode() === 200;
        } catch (\Throwable $e) {
            $this->logFailure('sendToDevice', $e, $deviceToken);

            // FCM v1 answers 404 specifically when the token is unregistered (app
            // uninstalled, token rotated, etc.) — that token will never succeed again,
            // so without cleanup it just fails silently on every future notification,
            // forever. Confirmed live: the same handful of tokens logged this exact
            // 404 dozens of times over more than a week straight.
            if ($e instanceof ClientException && $e->getResponse()?->getStatusCode() === 404) {
                try {
                    FcmToken::where('token', $deviceToken)->delete();
                } catch (\Throwable) {
                }
            }

            return false;
        }
    }

    /**
     * @param  array<int, string>  $deviceTokens
     * @param  array<string, mixed>  $notification
     * @param  array<string, string>|null  $data
     * @return array{success: int, failure: int, failed_tokens: array<int, string>}
     */
    public function sendToMultipleDevices(array $deviceTokens, array $notification, ?array $data = null): array
    {
        $results = [
            'success' => 0,
            'failure' => 0,
            'failed_tokens' => [],
        ];

        foreach ($deviceTokens as $token) {
            if ($this->sendToDevice($token, $notification, $data)) {
                $results['success']++;
            } else {
                $results['failure']++;
                $results['failed_tokens'][] = $token;
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $notification
     * @param  array<string, string>|null  $data
     */
    public function sendToTopic(string $topic, array $notification, ?array $data = null): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $accessToken = $this->getAccessToken();

            $message = [
                'topic' => $topic,
                'notification' => [
                    'title' => (string) ($notification['title'] ?? ''),
                    'body' => (string) ($notification['body'] ?? ''),
                ],
            ];

            if ($data !== null && $data !== []) {
                $message['data'] = $this->stringifyData($data);
            }

            $client = new Client;
            $response = $client->post(
                "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send",
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$accessToken,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'message' => $message,
                    ],
                ]
            );

            return $response->getStatusCode() === 200;
        } catch (\Throwable $e) {
            $this->logFailure('sendToTopic', $e);

            return false;
        }
    }

    public function sendOrderNotification(string $deviceToken, array $orderData): bool
    {
        $notification = [
            'title' => $orderData['title'] ?? __('notification.new_order'),
            'body' => $orderData['body'] ?? '',
            'sound' => 'default',
            'badge' => '1',
        ];

        $data = [
            'type' => 'order',
            'order_id' => (string) $orderData['order_id'],
            'order_number' => (string) ($orderData['order_number'] ?? ''),
            'status' => (string) ($orderData['status'] ?? ''),
            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
        ];

        return $this->sendToDevice($deviceToken, $notification, $data);
    }

    public function sendChatNotification(string $deviceToken, array $messageData): bool
    {
        $notification = [
            'title' => $messageData['sender_name'] ?? __('notification.new_message'),
            'body' => $messageData['message'] ?? '',
            'sound' => 'default',
            'badge' => '1',
        ];

        $data = [
            'type' => 'chat_message',
            'conversation_id' => (string) $messageData['conversation_id'],
            'message_id' => (string) $messageData['message_id'],
            'sender_id' => (string) $messageData['sender_id'],
            'sender_type' => (string) $messageData['sender_type'],
            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
        ];

        return $this->sendToDevice($deviceToken, $notification, $data);
    }

    private function resolveServiceAccountPath(): string
    {
        $configured = (string) config('services.firebase.service_account_path');

        $candidates = array_filter([
            $configured !== '' ? $configured : null,
            $configured !== '' ? base_path($configured) : null,
            storage_path('app/firebase/nathefah-146b3-firebase-adminsdk-fbsvc-26b2bd2f10.json'),
            storage_path('app/firebase/service-account.json'),
        ]);

        foreach (array_unique($candidates) as $path) {
            if (is_string($path) && is_readable($path)) {
                return $path;
            }
        }

        $discovered = glob(storage_path('app/firebase/*.json')) ?: [];

        return $discovered[0] ?? $configured;
    }

    private function readProjectIdFromServiceAccount(): ?string
    {
        if (! is_readable($this->serviceAccountPath)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($this->serviceAccountPath), true);

        return is_array($json) ? ($json['project_id'] ?? null) : null;
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

    private function logFailure(string $action, \Throwable $e, ?string $deviceToken = null): void
    {
        try {
            Log::warning('FCM push failed', [
                'action' => $action,
                'project_id' => $this->projectId,
                'service_account' => $this->serviceAccountPath,
                'token_preview' => $deviceToken ? substr($deviceToken, 0, 16).'...' : null,
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable) {
        }
    }
}
