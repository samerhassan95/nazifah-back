<?php

namespace App\Support;

use App\Enums\OrderStatus;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderStatusLog;

/**
 * Localized API payloads for order status-log endpoints (vendor, driver, etc.).
 */
class OrderStatusLogPresenter
{
    public const TIMEZONE = 'Asia/Riyadh';

    /** Translation keys whose EN/AR stored text should map back to the active locale. */
    private const EXACT_NOTE_KEYS = [
        'order.status_log_order_created',
        'order.status_log_order_fully_paid_checkout',
        'order.status_log_vendor_review_modifications',
        'order.status_log_vendor_accepted_all',
        'order.status_log_vendor_auto_confirmed',
        'order.status_log_client_approved_modifications',
        'order.status_log_client_rejected_modifications',
        'order.status_log_payment_cod',
        'order.status_log_payment_wallet',
        'order.status_log_deleted_by_user',
        'order.status_log_receipt_accepted',
        'order.status_log_surcharge_confirmed',
        'order.status_log_auto_awaiting_payment',
        'order.status_log_driver_waiting_receipt',
        'order.status_log_driver_picked_vendor_on_way',
        'order.status_log_driver_picked_client',
        'order.status_log_delivered_branch_qr',
        'order.status_log_cancelled_by_admin',
        'order.status_log_auto_internal_payment',
        'order.status_log_driver_on_way_pickup',
        'order.status_log_driver_on_way_delivery',
        'order.status_log_driver_qr_delivery_on_way',
        'order.handoff_log_give_to_driver',
        'order.handoff_log_receive_from_driver_completed',
        'order.handoff_log_receive_from_driver_delivered',
        'order.handoff_log_receive_from_laundry',
        'order.handoff_log_repair_branch_completed',
        'order.vendor_handoff_log_pickup_received',
        'order.vendor_handoff_log_ready_for_pickup',
        'order.vendor_handoff_log_client_pickup_received',
        'order.visit_log_confirm_receipt',
        'order.visit_log_confirm_pickup_on_way',
        'order.visit_log_confirm_delivery_on_way',
    ];

    /**
     * Vendor API shape (PascalCase keys).
     */
    public static function forVendor(OrderStatusLog $log, Order $order): array
    {
        $at = $log->created_at?->copy()->timezone(self::TIMEZONE);
        $isCod = $order->isCashOnDelivery() || in_array('cash_on_delivery', $order->resolvedPaymentMethods(), true);

        $title = self::title($log->status, $order->payment_method);
        $subTitle = self::localizeNote($log->notes);

        if ($log->status === OrderStatus::PAYMENT_CONFIRMED->value && $isCod) {
            $title = __('order.status_log_title_cod');
            $subTitle = __('order.status_log_subtitle_cod');
        }

        return [
            'status' => $log->status,
            'Order_number' => $order->order_number,
            'Status_date' => $at?->format('Y-m-d'),
            'Status_time' => $at?->format('H:i:s'),
            'Title' => $title,
            'sub_title' => $subTitle,
        ];
    }

    /**
     * Driver API shape (snake_case keys).
     */
    public static function forDriver(OrderStatusLog $log, Order $order): array
    {
        $at = $log->created_at?->copy()->timezone(self::TIMEZONE);
        $isCod = $order->isCashOnDelivery() || in_array('cash_on_delivery', $order->resolvedPaymentMethods(), true);

        $title = self::title($log->status, $order->payment_method);
        $subTitle = self::localizeNote($log->notes);

        if ($log->status === OrderStatus::PAYMENT_CONFIRMED->value && $isCod) {
            $title = __('order.status_log_title_cod');
            $subTitle = __('order.status_log_subtitle_cod');
        }

        return [
            'status' => $log->status,
            'order_number' => $order->order_number,
            'status_date' => $at?->format('Y-m-d'),
            'status_time' => $at?->format('H:i:s'),
            'title' => $title,
            'sub_title' => $subTitle,
        ];
    }

    public static function title(string $status, ?string $paymentMethod = null): string
    {
        $enum = OrderStatus::tryFrom($status);

        return $enum ? $enum->localizedLabel($paymentMethod) : ucfirst(str_replace('_', ' ', $status));
    }

    public static function localizeNote(?string $notes): string
    {
        if ($notes === null || trim($notes) === '') {
            return __('order.status_log_notes_updated');
        }

        $notes = trim($notes);

        foreach (self::regexPatterns() as $pattern) {
            if (preg_match($pattern['regex'], $notes, $matches)) {
                $params = [];
                foreach ($pattern['params'] ?? [] as $param) {
                    $params[$param] = $matches[$param]
                        ?? $matches[$param.'_en']
                        ?? $matches[$param.'_ar']
                        ?? null;
                }

                return __($pattern['key'], array_filter($params, fn ($v) => $v !== null));
            }
        }

        foreach (self::EXACT_NOTE_KEYS as $key) {
            if ($notes === __($key, [], 'en') || $notes === __($key, [], 'ar')) {
                return __($key);
            }
        }

        return $notes;
    }

    /**
     * @return array<int, array{key: string, regex: string, params?: array<int, string>}>
     */
    private static function regexPatterns(): array
    {
        return [
            [
                'key' => 'order.status_log_pickup_driver_assigned',
                'regex' => '/^(?:Pickup driver assigned|تعيين مندوب الاستلام): #(?<id>\d+)$/u',
                'params' => ['id'],
            ],
            [
                'key' => 'order.status_log_pickup_driver_assigned_postponed',
                'regex' => '/^(?:Pickup driver assigned after client postponed pickup|تعيين مندوب الاستلام بعد تأجيل العميل): #(?<id>\d+)$/u',
                'params' => ['id'],
            ],
            [
                'key' => 'order.status_log_pickup_driver_reassigned',
                'regex' => '/^(?:Pickup driver re-assigned|إعادة تعيين مندوب الاستلام): #(?<id>\d+)$/u',
                'params' => ['id'],
            ],
            [
                'key' => 'order.status_log_pickup_driver_reassigned_from',
                'regex' => '/^(?:Pickup driver re-assigned \(from|إعادة تعيين مندوب الاستلام \(من) (?<status>[^)]+)\): #(?<id>\d+)$/u',
                'params' => ['status', 'id'],
            ],
            [
                'key' => 'order.status_log_delivery_driver_assigned',
                'regex' => '/^(?:Delivery driver assigned|تعيين مندوب التوصيل): #(?<id>\d+)$/u',
                'params' => ['id'],
            ],
            [
                'key' => 'order.status_log_delivery_driver_assigned_postponed',
                'regex' => '/^(?:Delivery driver assigned after client postponed delivery|تعيين مندوب التوصيل بعد تأجيل العميل): #(?<id>\d+)$/u',
                'params' => ['id'],
            ],
            [
                'key' => 'order.status_log_delivery_driver_reassigned',
                'regex' => '/^(?:Delivery driver re-assigned|إعادة تعيين مندوب التوصيل): #(?<id>\d+)$/u',
                'params' => ['id'],
            ],
            [
                'key' => 'order.status_log_delivery_driver_reassigned_from',
                'regex' => '/^(?:Delivery driver re-assigned \(from|إعادة تعيين مندوب التوصيل \(من) (?<status>[^)]+)\): #(?<id>\d+)$/u',
                'params' => ['status', 'id'],
            ],
            [
                'key' => 'order.status_log_pickup_driver_accepted',
                'regex' => '/^(?:Pickup driver #|قبل مندوب الاستلام #)(?<id>\d+)(?: accepted| الطلب)$/u',
                'params' => ['id'],
            ],
            [
                'key' => 'order.status_log_delivery_driver_accepted',
                'regex' => '/^(?:Delivery driver #|قبل مندوب التوصيل #)(?<id>\d+)(?: accepted| الطلب)$/u',
                'params' => ['id'],
            ],
            [
                'key' => 'order.status_log_default_transition',
                'regex' => '/^(?:Status changed from|تغيير الحالة من) (?<from>.+?) (?:to|إلى) (?<to>.+)$/u',
                'params' => ['from', 'to'],
            ],
            [
                'key' => 'order.visit_log_postponed',
                'regex' => '/^(?:Client postponed|أجّل العميل) (?<leg>.+?): (?<reason>.+)\. (?:Rescheduled to|الموعد الجديد): (?<time>.+)$/u',
                'params' => ['leg', 'reason', 'time'],
            ],
            [
                'key' => 'order.status_log_auto_payment_checkout',
                'regex' => '/^(?:Auto|تلقائي): (?<reason>.+?) — (?:payment already completed at checkout|الدفع مكتمل مسبقاً عند الطلب)$/u',
                'params' => ['reason'],
            ],
            [
                'key' => 'order.status_log_auto_cod',
                'regex' => '/^(?:Auto|تلقائي): (?<reason>.+?) — (?:cash on delivery \(pay at order completion\)|دفع عند الاستلام)$/u',
                'params' => ['reason'],
            ],
            [
                'key' => 'order.status_log_auto_proceeding_payment',
                'regex' => '/^(?:Auto|تلقائي): (?<reason>.+?) — (?:proceeding to payment|الانتقال لخطوة الدفع)$/u',
                'params' => ['reason'],
            ],
            [
                'key' => 'order.status_log_driver_rejected',
                'regex' => '/^(?:رفض مندوب (?<type>[^:]+) #(?<id>\d+): (?<reason>.+) — الطلب متاح لإعادة التعيين|(?<type_en>\w+) driver #(?<id_en>\d+) rejected: (?<reason_en>.+) — order available for re-assignment)$/u',
                'params' => ['type', 'id', 'reason'],
            ],
            [
                'key' => 'order.status_log_driver_rejected_no_reason',
                'regex' => '/^(?:رفض مندوب (?<type>[^:]+) #(?<id>\d+) — الطلب متاح لإعادة التعيين|(?<type_en>\w+) driver #(?<id_en>\d+) rejected — order available for re-assignment)$/u',
                'params' => ['type', 'id'],
            ],
            [
                'key' => 'order.vendor_log_order_rejected',
                'regex' => '/^(?:Order rejected by vendor|رفض المغسلة للطلب): (?<reason>.+)$/u',
                'params' => ['reason'],
            ],
        ];
    }
}
