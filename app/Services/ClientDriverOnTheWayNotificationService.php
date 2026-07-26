<?php

namespace App\Services;

use App\Enums\OrderStatus;
use Modules\Order\Models\Order;

class ClientDriverOnTheWayNotificationService
{
    public function __construct(
        protected OrderNotificationService $notifications,
    ) {}

    /**
     * Notify client that the driver is on the way (pickup from client or delivery to client).
     * Does not change order status.
     */
    public function send(Order $order, ?string $leg = null): bool
    {
        $order = $order->fresh(['client']);
        $client = $order->client;
        if (! $client) {
            return false;
        }

        $leg = $leg ?? $this->resolveLeg($order);
        if ($leg === null) {
            return false;
        }

        [$titleAr, $titleEn, $bodyAr, $bodyEn] = $this->buildMessages($order, $leg);
        $visitMeta = $this->visitTypeMeta($leg, $order);

        $this->notifications->sendToClient(
            $order,
            $titleAr,
            $titleEn,
            $bodyAr,
            $bodyEn,
            (string) ($visitMeta['subtype'] ?? 'driver_on_the_way'),
            $visitMeta
        );

        return true;
    }

    /**
     * @return 'pickup'|'delivery'|null
     */
    public function resolveLeg(Order $order): ?string
    {
        $status = $order->status;

        if ($status === OrderStatus::ON_WAY_TO_DELIVERY->value
            && ! (bool) $order->delivery_at_vendor
            && $order->delivery_driver_id) {
            return 'delivery';
        }

        if ($status === OrderStatus::ON_WAY_TO_PICKUP->value
            && ! (bool) $order->pickup_at_vendor
            && $order->pickup_driver_id) {
            return 'pickup';
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    public function buildMessages(Order $order, string $leg): array
    {
        $num = $order->order_number;

        if ($leg === 'pickup') {
            return [
                __('order.driver_on_the_way_title_pickup'),
                __('order.driver_on_the_way_title_pickup', [], 'en'),
                __('order.driver_on_the_way_body_pickup', ['order_number' => $num]),
                __('order.driver_on_the_way_body_pickup_en', ['order_number' => $num]),
            ];
        }

        return [
            __('order.driver_on_the_way_title_delivery'),
            __('order.driver_on_the_way_title_delivery', [], 'en'),
            __('order.driver_on_the_way_body_delivery', ['order_number' => $num]),
            __('order.driver_on_the_way_body_delivery_en', ['order_number' => $num]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function visitTypeMeta(string $leg, Order $order): array
    {
        $statusEnum = OrderStatus::tryFrom($order->status);

        return [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'subtype' => "driver_on_the_way_{$leg}",
            'visit_type' => $leg,
            'visit_type_label' => [
                'ar' => $leg === 'pickup'
                    ? __('order.visit_type_pickup', [], 'ar')
                    : __('order.visit_type_delivery', [], 'ar'),
                'en' => $leg === 'pickup'
                    ? __('order.visit_type_pickup', [], 'en')
                    : __('order.visit_type_delivery', [], 'en'),
            ],
            'order_status' => $order->status,
            'order_status_label' => [
                'ar' => $statusEnum?->labelAr() ?? $order->status,
                'en' => $statusEnum?->label() ?? $order->status,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, string>
     */
    protected function fcmDataPayload(array $meta): array
    {
        return [
            'type' => (string) ($meta['subtype'] ?? ''),
            'order_id' => (string) ($meta['order_id'] ?? ''),
            'order_number' => (string) ($meta['order_number'] ?? ''),
            'visit_type' => (string) ($meta['visit_type'] ?? ''),
            'visit_type_label_ar' => (string) ($meta['visit_type_label']['ar'] ?? ''),
            'visit_type_label_en' => (string) ($meta['visit_type_label']['en'] ?? ''),
            'order_status' => (string) ($meta['order_status'] ?? ''),
            'order_status_label_ar' => (string) ($meta['order_status_label']['ar'] ?? ''),
            'order_status_label_en' => (string) ($meta['order_status_label']['en'] ?? ''),
        ];
    }

    /**
     * @return 'pickup'|'delivery'|null
     */
    public function resolveNotifyLegForDriver(Order $order, int $driverId, ?string $preferredLeg = null): ?string
    {
        $isPickupDriver = (int) $order->pickup_driver_id === $driverId;
        $isDeliveryDriver = (int) $order->delivery_driver_id === $driverId;
        $status = $order->status;

        if ($preferredLeg === 'pickup') {
            if ($isPickupDriver && ! (bool) $order->pickup_at_vendor && in_array($status, self::pickupDriverCanNotifyStatuses(), true)) {
                return 'pickup';
            }

            return null;
        }

        if ($preferredLeg === 'delivery') {
            if ($isDeliveryDriver && ! (bool) $order->delivery_at_vendor && in_array($status, self::deliveryDriverCanNotifyStatuses(), true)) {
                return 'delivery';
            }

            return null;
        }

        if ($isDeliveryDriver && ! (bool) $order->delivery_at_vendor && in_array($status, self::deliveryDriverCanNotifyStatuses(), true)) {
            return 'delivery';
        }

        if ($isPickupDriver && ! (bool) $order->pickup_at_vendor && in_array($status, self::pickupDriverCanNotifyStatuses(), true)) {
            return 'pickup';
        }

        return null;
    }

    public static function requiredStatusForLeg(string $leg): string
    {
        return $leg === 'pickup'
            ? OrderStatus::ON_WAY_TO_PICKUP->value
            : OrderStatus::ON_WAY_TO_DELIVERY->value;
    }

    /**
     * @return list<string>
     */
    public static function pickupDriverCanNotifyStatuses(): array
    {
        return [
            OrderStatus::DRIVER_PICKUP_ASSIGNED->value,
            OrderStatus::DRIVER_PICKUP_ACCEPTED->value,
            OrderStatus::ON_WAY_TO_PICKUP->value,
            OrderStatus::PAYMENT_CONFIRMED->value,
        ];
    }

    /**
     * @return list<string>
     */
    public static function deliveryDriverCanNotifyStatuses(): array
    {
        return [
            OrderStatus::DRIVER_DELIVERY_ASSIGNED->value,
            OrderStatus::DRIVER_DELIVERY_ACCEPTED->value,
            OrderStatus::ON_WAY_TO_DELIVERY->value,
        ];
    }
}
