<?php

namespace App\Services;

use Modules\Driver\Models\Driver;
use Modules\Order\Models\Order;
use Modules\Vendor\Models\VendorEmployee;

class ClientOrderVisitNotificationService
{
    public function __construct(
        protected OrderNotificationService $notifications,
    ) {}

    public function notifyConfirmed(Order $order, string $leg): void
    {
        $order = $order->fresh(['branch']);
        $num = $order->order_number;

        if ($leg === 'pickup') {
            $this->notifications->sendToVendorBranch(
                $order,
                __('order.visit_notify_vendor_confirm_pickup_title'),
                'Client ready for pickup',
                __('order.visit_notify_vendor_confirm_pickup_body', ['order_number' => $num]),
                __('order.visit_notify_vendor_confirm_pickup_body_en', ['order_number' => $num]),
                'client_visit_confirmed_pickup'
            );
            $this->notifications->sendToDriver(
                $order,
                (int) $order->pickup_driver_id,
                __('order.visit_notify_driver_confirm_pickup_title'),
                'Client ready for pickup',
                __('order.visit_notify_driver_confirm_pickup_body', ['order_number' => $num]),
                __('order.visit_notify_driver_confirm_pickup_body_en', ['order_number' => $num]),
                'client_visit_confirmed_pickup'
            );

            return;
        }

        $this->notifications->sendToVendorBranch(
            $order,
            __('order.visit_notify_vendor_confirm_delivery_title'),
            'Client ready for delivery',
            __('order.visit_notify_vendor_confirm_delivery_body', ['order_number' => $num]),
            __('order.visit_notify_vendor_confirm_delivery_body_en', ['order_number' => $num]),
            'client_visit_confirmed_delivery'
        );
        $this->notifications->sendToDriver(
            $order,
            (int) $order->delivery_driver_id,
            __('order.visit_notify_driver_confirm_delivery_title'),
            'Client ready for delivery',
            __('order.visit_notify_driver_confirm_delivery_body', ['order_number' => $num]),
            __('order.visit_notify_driver_confirm_delivery_body_en', ['order_number' => $num]),
            'client_visit_confirmed_delivery'
        );
    }

    /**
     * @param  int|null  $pickupDriverId  Captured before driver unassignment (postpone pickup)
     * @param  int|null  $deliveryDriverId  Captured before driver unassignment (postpone delivery)
     */
    public function notifyPostponed(
        Order $order,
        string $leg,
        ?int $pickupDriverId = null,
        ?int $deliveryDriverId = null
    ): void {
        $order = $order->fresh(['branch']);
        $num = $order->order_number;
        $time = $leg === 'pickup'
            ? ($order->pickup_time?->format('Y-m-d H:i') ?? '')
            : ($order->estimated_delivery_time?->format('Y-m-d H:i') ?? '');
        $reason = $order->client_postpone_reason ?? '';

        if ($leg === 'pickup') {
            $this->notifications->sendToVendorBranch(
                $order,
                __('order.visit_notify_vendor_postpone_pickup_title'),
                'Pickup postponed by client',
                __('order.visit_notify_vendor_postpone_pickup_body', [
                    'order_number' => $num,
                    'time' => $time,
                    'reason' => $reason,
                ]),
                __('order.visit_notify_vendor_postpone_pickup_body_en', [
                    'order_number' => $num,
                    'time' => $time,
                    'reason' => $reason,
                ]),
                'client_postponed_pickup'
            );
            $this->notifications->sendToDriver(
                $order,
                $pickupDriverId ?: (int) $order->pickup_driver_id,
                __('order.visit_notify_driver_postpone_pickup_title'),
                'Pickup postponed',
                __('order.visit_notify_driver_postpone_pickup_body', ['order_number' => $num, 'time' => $time]),
                __('order.visit_notify_driver_postpone_pickup_body_en', ['order_number' => $num, 'time' => $time]),
                'client_postponed_pickup'
            );

            return;
        }

        $this->notifications->sendToVendorBranch(
            $order,
            __('order.visit_notify_vendor_postpone_delivery_title'),
            'Delivery postponed by client',
            __('order.visit_notify_vendor_postpone_delivery_body', [
                'order_number' => $num,
                'time' => $time,
                'reason' => $reason,
            ]),
            __('order.visit_notify_vendor_postpone_delivery_body_en', [
                'order_number' => $num,
                'time' => $time,
                'reason' => $reason,
            ]),
            'client_postponed_delivery'
        );
        $this->notifications->sendToDriver(
            $order,
            $deliveryDriverId ?: (int) $order->delivery_driver_id,
            __('order.visit_notify_driver_postpone_delivery_title'),
            'Delivery postponed',
            __('order.visit_notify_driver_postpone_delivery_body', ['order_number' => $num, 'time' => $time]),
            __('order.visit_notify_driver_postpone_delivery_body_en', ['order_number' => $num, 'time' => $time]),
            'client_postponed_delivery'
        );
    }
}
