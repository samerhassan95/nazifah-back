<?php

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Events\DriverAssigned;
use App\Services\OrderNotificationService;

class SendDriverAssignmentNotification
{
    public function __construct(protected OrderNotificationService $notifications) {}

    public function handle(DriverAssigned $event): void
    {
        try {
            $order = $event->order;
            $driver = $event->driver;
            $type = $event->assignmentType;
            $num = $order->order_number;

            $titleAr = $type === 'pickup' ? 'طلب استلام جديد' : 'طلب توصيل جديد';
            $titleEn = $type === 'pickup' ? 'New Pickup Assignment' : 'New Delivery Assignment';
            $bodyAr = $type === 'pickup'
                ? "تم تعيينك لاستلام الطلب #{$num}."
                : "تم تعيينك لتوصيل الطلب #{$num}.";
            $bodyEn = $type === 'pickup'
                ? "You have been assigned to pick up order #{$num}."
                : "You have been assigned to deliver order #{$num}.";

            $this->notifications->sendToDriver(
                $order,
                (int) $driver->id,
                $titleAr,
                $titleEn,
                $bodyAr,
                $bodyEn,
                "driver_{$type}_assigned",
                ['assignment_type' => $type]
            );

            $isReassignment = ($type === 'pickup' && $order->status === OrderStatus::DRIVER_PICKUP_ASSIGNED->value)
                || ($type === 'delivery' && $order->status === OrderStatus::DRIVER_DELIVERY_ASSIGNED->value);

            if ($isReassignment) {
                $this->notifications->sendToVendorAndAdmins(
                    $order,
                    $type === 'pickup' ? 'تم تعيين سائق استلام جديد' : 'تم تعيين سائق توصيل جديد',
                    $type === 'pickup' ? 'New Pickup Driver Assigned' : 'New Delivery Driver Assigned',
                    $type === 'pickup'
                        ? "تم تعيين سائق استلام جديد للطلب #{$num}."
                        : "تم تعيين سائق توصيل جديد للطلب #{$num}.",
                    $type === 'pickup'
                        ? "A new pickup driver was assigned to order #{$num}."
                        : "A new delivery driver was assigned to order #{$num}.",
                    "driver_{$type}_reassigned",
                );
            }
        } catch (\Throwable $e) {
            try {
                \Illuminate\Support\Facades\Log::warning('Driver assignment notification failed', [
                    'order_id' => $event->order->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
            }
        }
    }
}
