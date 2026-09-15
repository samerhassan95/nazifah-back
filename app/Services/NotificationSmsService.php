<?php

namespace App\Services;

use App\Support\NotificationLocale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class NotificationSmsService
{
    /**
     * Notification types that must stay push-only (in-app + FCM), never SMS,
     * regardless of the deewan.notification_sms.types config. These are
     * driver "your pending pickup/delivery request was cancelled" notices —
     * frequent, low-stakes to miss, and not worth an SMS cost each time.
     */
    private const PUSH_ONLY_TYPES = [
        'driver_pickup_cancelled_client_edit',
        'driver_delivery_cancelled_client_edit',
        'driver_pickup_unassigned',
        'driver_delivery_unassigned',
    ];

    /**
     * Client notification types allowed to go out via SMS — everything else the
     * client gets stays push/in-app only. This is the allowlist from the
     * client's sms-notifications spreadsheet (rows marked with a 1 in the
     * "التعديل" column). Driver/vendor/admin notifications are unaffected —
     * they still follow the deewan.notification_sms.types config.
     */
    private const CLIENT_SMS_ALLOWED_TYPES = [
        'order_placed',                // تم استلام الطلب (بعد إتمام الدفع)
        'order_reviewed',              // المغسلة عدّلت الطلب (بانتظار الموافقة)
        'driver_on_the_way_pickup',    // السائق في الطريق للاستلام
        'order_picked_up',             // تم استلام الطلب من العميل
        'driver_on_the_way_delivery',  // السائق في الطريق للتوصيل
        'waiting_client_receipt',      // الطلب جاهز (فرع) / السائق في موقع التسليم
        'order_delivered',             // تم التوصيل
    ];

    /**
     * SMS-only wording, deliberately separate from the push/in-app body text —
     * the client's "sms client (2).xlsx" (Sheet1) spells out different, more
     * detailed copy for the SMS channel specifically. Only types allowed to
     * send SMS at all (CLIENT_SMS_ALLOWED_TYPES) are covered here; everything
     * else keeps using the push body as its SMS body, unaffected.
     */
    private const CLIENT_SMS_TEXT = [
        'order_placed' => [
            'ar' => 'تم استلام طلبك رقم {order_number} بنجاح. شكرًا لاختيارك نظيفة.',
            'en' => 'Your order number {order_number} has been received successfully. Thank you for choosing Nathefah.',
        ],
        'order_reviewed' => [
            'ar' => 'تم تعديل طلبك رقم {order_number} من قِبل المغسلة. يرجى مراجعة التعديلات والموافقة عليها.',
            'en' => 'Your order number {order_number} has been modified by the laundry. Please review the changes and approve them.',
        ],
        'driver_on_the_way_pickup' => [
            'ar' => 'الطلب رقم {order_number}، السائق في الطريق إليك للاستلام يرجى تأكيد جاهزيتك للتسليم.',
            'en' => 'Order number {order_number}: the driver is on the way to pick up your items. Please confirm you are ready for the handover.',
        ],
        'order_picked_up' => [
            'ar' => 'تم استلام طلبك رقم {order_number} من السائق، يرجى تأكيد تسليم الطلب.',
            'en' => 'Your order number {order_number} has been picked up by the driver. Please confirm the handover.',
        ],
        'driver_on_the_way_delivery' => [
            'ar' => 'الطلب رقم {order_number}، السائق في الطريق إليك للتسليم، يرجى تأكيد جاهزيتك لاستلام الطلب.',
            'en' => 'Order number {order_number}: the driver is on the way to deliver your order. Please confirm you are ready to receive it.',
        ],
        'order_delivered' => [
            'ar' => 'تم توصيل طلبك رقم {order_number} بنجاح. يرجى تأكيد استلام الطلب.',
            'en' => 'Your order number {order_number} has been delivered successfully. Please confirm receipt of your order.',
        ],
    ];

    /**
     * waiting_client_receipt is one notification type shared by two different
     * scenarios (see SendOrderStatusNotification::onWaitingClientReceipt) — a
     * branch self-pickup order being ready vs. a delivery driver having
     * arrived — and the spreadsheet gives each its own SMS wording.
     */
    private const WAITING_CLIENT_RECEIPT_SMS_TEXT = [
        'branch' => [
            'ar' => 'طلبك رقم {order_number} جاهز للاستلام من الفرع. نسعد بخدمتك!',
            'en' => "Your order number {order_number} is ready for pickup at the branch. We're happy to serve you.",
        ],
        'driver' => [
            'ar' => 'وصل السائق إلى موقع تسليم طلبك رقم {order_number}. يرجى استلام الطلب.',
            'en' => 'The driver has arrived at the delivery location. Please receive order number {order_number}.',
        ],
    ];

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
        $lang = is_string($lang) ? $lang : 'ar';

        $body = ($userType === 'client'
            ? $this->resolveClientSmsText((string) ($data['notification_type'] ?? ''), $data, $lang)
            : null)
            ?? NotificationLocale::pick($bodyAr, $bodyEn, $lang);
        if (trim($body) === '') {
            $body = NotificationLocale::pick($titleAr, $titleEn, $lang);
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
     * SMS-specific wording for a client notification type, or null to fall
     * back to the push body. waiting_client_receipt has two scenarios sharing
     * one type — distinguished by the delivery_at_vendor flag passed in $data.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveClientSmsText(string $type, array $data, string $lang): ?string
    {
        if ($type === 'waiting_client_receipt') {
            $variant = ((bool) ($data['delivery_at_vendor'] ?? false)) ? 'branch' : 'driver';
            $template = self::WAITING_CLIENT_RECEIPT_SMS_TEXT[$variant];
        } elseif (isset(self::CLIENT_SMS_TEXT[$type])) {
            $template = self::CLIENT_SMS_TEXT[$type];
        } else {
            return null;
        }

        $text = $lang === 'en' ? $template['en'] : $template['ar'];

        return str_replace('{order_number}', (string) ($data['order_number'] ?? ''), $text);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function shouldSend(string $userType, array $data): bool
    {
        $notificationType = (string) ($data['notification_type'] ?? '');
        if ($notificationType !== '' && in_array($notificationType, self::PUSH_ONLY_TYPES, true)) {
            return false;
        }

        if ($userType === 'client' && ! in_array($notificationType, self::CLIENT_SMS_ALLOWED_TYPES, true)) {
            return false;
        }

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
