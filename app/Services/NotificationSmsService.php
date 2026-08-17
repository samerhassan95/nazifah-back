<?php

namespace App\Services;

use App\Support\NotificationLocale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class NotificationSmsService
{
    public function __construct(protected DeewanSmsService $deewanSms) {}

    /**
     * Send an order/system notification via the same Deewan sender used for OTP.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendIfEnabled(
        Model $user,
        string $userType,
        string $titleAr,
        string $titleEn,
        string $bodyAr,
        string $bodyEn,
        array $data = []
    ): void {
        if (! $this->shouldSend($userType, $data)) {
            return;
        }

        $phone = $this->resolvePhone($user);
        if ($phone === null) {
            return;
        }

        $lang = method_exists($user, 'getNotificationLang')
            ? $user->getNotificationLang()
            : (property_exists($user, 'lang') ? $user->lang : 'ar');

        $body = NotificationLocale::pick($bodyAr, $bodyEn, is_string($lang) ? $lang : 'ar');
        if (trim($body) === '') {
            $body = NotificationLocale::pick($titleAr, $titleEn, is_string($lang) ? $lang : 'ar');
        }

        if (trim($body) === '') {
            return;
        }

        try {
            $this->deewanSms->sendSms($phone, $body);
        } catch (\Throwable $e) {
            try {
                Log::warning('Notification SMS send failed', [
                    'user_type' => $userType,
                    'user_id' => $user->id ?? null,
                    'notification_type' => $data['notification_type'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function shouldSend(string $userType, array $data): bool
    {
        if (! (bool) config('deewan.notification_sms.enabled', false)) {
            return false;
        }

        if (! $this->deewanSms->isEnabled()) {
            return false;
        }

        $audiences = $this->csvList((string) config('deewan.notification_sms.audiences', 'client,vendor,driver'));
        if (! in_array($userType, $audiences, true) && ! in_array('*', $audiences, true)) {
            return false;
        }

        $types = $this->csvList((string) config('deewan.notification_sms.types', ''));
        if ($types === [] || in_array('*', $types, true)) {
            return true;
        }

        $notificationType = (string) ($data['notification_type'] ?? '');

        return $notificationType !== '' && in_array($notificationType, $types, true);
    }

    /**
     * @return list<string>
     */
    private function csvList(string $value): array
    {
        return array_values(array_filter(array_map(
            static fn (string $item) => strtolower(trim($item)),
            explode(',', $value)
        )));
    }

    private function resolvePhone(Model $user): ?string
    {
        $phone = $user->phone ?? $user->phone_number ?? null;
        if (! is_string($phone) || trim($phone) === '') {
            return null;
        }

        return trim($phone);
    }
}
