<?php

namespace App\Listeners;

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

            $previousDriverId = $event->previousDriverId;
            $isReassignment = $previousDriverId !== null && $previousDriverId !== (int) $driver->id;

            if ($isReassignment) {
                $titleArOld = $type === 'pickup' ? 'تم إلغاء تعيينك من طلب استلام' : 'تم إلغاء تعيينك من طلب توصيل';
                $titleEnOld = $type === 'pickup' ? 'Removed from Pickup Assignment' : 'Removed from Delivery Assignment';
                $bodyArOld = $type === 'pickup'
                    ? "تم إلغاء تعيينك لاستلام الطلب #{$num} وتحويله لسائق آخر."
                    : "تم إلغاء تعيينك لتوصيل الطلب #{$num} وتحويله لسائق آخر.";
                $bodyEnOld = $type === 'pickup'
                    ? "You have been removed from picking up order #{$num}; it was reassigned to another driver."
                    : "You have been removed from delivering order #{$num}; it was reassigned to another driver.";

                $this->notifications->sendToDriver(
                    $order,
                    $previousDriverId,
                    $titleArOld,
                    $titleEnOld,
                    $bodyArOld,
                    $bodyEnOld,
                    "driver_{$type}_unassigned",
                    ['assignment_type' => $type]
                );

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
