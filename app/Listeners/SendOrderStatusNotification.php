<?php

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Services\ClientDriverOnTheWayNotificationService;
use App\Services\OrderNotificationService;
use Illuminate\Support\Facades\Log;
use Modules\Order\Models\Order;

class SendOrderStatusNotification
{
    public function __construct(protected OrderNotificationService $notifications) {}

    public function handle(OrderStatusChanged $event): void
    {
        try {
            // Internal auto-transitions (e.g. paid/COD → payment_confirmed after
            // driver accept) must not spam clients/vendors/admins.
            if (! empty($event->context['skip_notifications'])) {
                return;
            }

            $order = $event->order->fresh(['client', 'branch', 'vendor']);
            $status = $event->newStatus;
            $num = $order->order_number;
            $context = $event->context;

            match ($status) {
                OrderStatus::PENDING => $this->onPending($order, $num),
                OrderStatus::BRANCH_REVIEW => $this->onBranchReview($order, $num),
                OrderStatus::CONFIRMED => $this->onConfirmed($order, $num, $context),
                OrderStatus::WAITING_PAYMENT => $this->onWaitingPayment($order, $num),
                OrderStatus::PAYMENT_CONFIRMED => $this->onPaymentConfirmed($order, $num),
                OrderStatus::DRIVER_PICKUP_ASSIGNED => $this->onDriverPickupAssigned($order, $num),
                OrderStatus::DRIVER_PICKUP_ACCEPTED => $this->onDriverPickupAccepted($order, $num),
                OrderStatus::ON_WAY_TO_PICKUP => $this->onDriverOnTheWayToClient($order, 'pickup'),
                OrderStatus::PICKED_UP => $this->onPickedUp($order, $num),
                OrderStatus::DELIVERED_TO_BRANCH => $this->onDeliveredToBranch($order, $num),
                OrderStatus::DRIVER_DELIVERY_ASSIGNED => $this->onDriverDeliveryAssigned($order, $num),
                OrderStatus::DRIVER_DELIVERY_ACCEPTED => $this->onDriverDeliveryAccepted($order, $num),
                OrderStatus::ON_WAY_TO_DELIVERY => $this->onDriverOnTheWayToClient($order, 'delivery'),
                OrderStatus::WAITING_CLIENT_RECEIPT => $this->onWaitingClientReceipt($order, $num),
                OrderStatus::DELIVERED => $this->onDelivered($order, $num),
                OrderStatus::CLIENT_POSTPONED_PICKUP => $this->onClientPostponedPickup($order, $num),
                OrderStatus::CLIENT_POSTPONED_DELIVERY => $this->onClientPostponedDelivery($order, $num),
                OrderStatus::COMPLETED => $this->onCompleted($order, $num),
                OrderStatus::CANCELLED => $this->onCancelled($order, $num),
                default => null,
            };
        } catch (\Throwable $e) {
            try {
                Log::warning('Order status notification failed', [
                    'order_id' => $event->order->id ?? null,
                    'status' => $event->newStatus->value ?? null,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
            }
        }
    }

    private function onPending(Order $order, string $num): void
    {
        $this->notifications->sendOrderCreatedNotificationsIfNeeded($order);
    }

    private function onBranchReview(Order $order, string $num): void
    {
        $this->notifications->sendToClient($order,
            'تحديثات على طلبك', 'Updates on Your Order',
            "قامت المغسلة بمراجعة طلبك #{$num}. يرجى المراجعة والموافقة.",
            "The laundry has reviewed your order #{$num}. Please review and approve.",
            'order_reviewed',
        );
        $this->notifyVendorAndAdmins($order,
            'تم إرسال المراجعة للعميل', 'Review Sent to Client',
            "تم إرسال مراجعة الطلب #{$num} للعميل.", "Order #{$num} review was sent to the client.",
            'order_reviewed',
        );
    }

    private function onConfirmed(Order $order, string $num, array $context = []): void
    {
        $this->notifications->sendToClient($order,
            'تم تأكيد الطلب', 'Order Confirmed',
            "تم تأكيد طلبك #{$num}.", "Your order #{$num} has been confirmed.",
            'order_confirmed',
        );

        // Vendor auto-accepted every item: the client did not approve anything.
        if (! empty($context['auto_confirmed'])) {
            return;
        }

        $this->notifyVendorAndAdmins($order,
            'وافق العميل على الطلب', 'Client Approved Order',
            "وافق العميل على الطلب #{$num}.", "Client approved order #{$num}.",
            'client_approved',
        );
    }

    private function onWaitingPayment(Order $order, string $num): void
    {
        $this->notifications->sendToClient($order,
            'في انتظار الدفع', 'Payment Required',
            "طلبك #{$num} بانتظار الدفع. يرجى إتمام الدفع لمواصلة المعالجة.",
            "Your order #{$num} is waiting for payment. Please complete payment to continue.",
            'waiting_payment',
        );
        $this->notifyVendorAndAdmins($order,
            'في انتظار دفع العميل', 'Waiting for Client Payment',
            "الطلب #{$num} بانتظار دفع العميل.", "Order #{$num} is waiting for client payment.",
            'waiting_payment',
        );
    }

    private function onPaymentConfirmed(Order $order, string $num): void
    {
        $this->notifications->sendToClient($order,
            'تم تأكيد الدفع', 'Payment Confirmed',
            "تم تأكيد الدفع لطلبك #{$num}.", "Payment for your order #{$num} has been confirmed.",
            'payment_confirmed',
        );
        $this->notifyVendorAndAdmins($order,
            'تم تأكيد الدفع', 'Payment Confirmed',
            "تم تأكيد الدفع للطلب #{$num}.", "Payment confirmed for order #{$num}.",
            'payment_confirmed',
        );
    }

    private function onDriverPickupAssigned(Order $order, string $num): void
    {
        $this->notifications->sendToClient($order,
            'تم تعيين سائق الاستلام', 'Pickup Driver Assigned',
            "تم تعيين سائق لاستلام طلبك #{$num}.", "A driver has been assigned to pick up your order #{$num}.",
            'driver_pickup_assigned',
        );
        $this->notifyVendorAndAdmins($order,
            'تم تعيين سائق الاستلام', 'Pickup Driver Assigned',
            "تم تعيين سائق استلام للطلب #{$num}.", "A pickup driver was assigned to order #{$num}.",
            'driver_pickup_assigned',
        );
    }

    private function onDriverPickupAccepted(Order $order, string $num): void
    {
        $this->notifications->sendToClient($order,
            'تم قبول الاستلام', 'Pickup Driver Accepted',
            "قبل السائق استلام طلبك #{$num}.", "A driver has accepted to pick up your order #{$num}.",
            'driver_pickup_accepted',
        );
        $this->notifyVendorAndAdmins($order,
            'قبل سائق الاستلام', 'Pickup Driver Accepted',
            "قبل سائق استلام الطلب #{$num}.", "Pickup driver accepted order #{$num}.",
            'driver_pickup_accepted',
        );
    }

    private function onPickedUp(Order $order, string $num): void
    {
        $this->notifications->sendToClient($order,
            'تم استلام الطلب', 'Order Picked Up',
            "تم استلام طلبك #{$num}.", "Your order #{$num} has been picked up.",
            'order_picked_up',
        );
        $this->notifyVendorAndAdmins($order,
            'تم استلام الطلب', 'Clothes Picked Up',
            "تم استلام الطلب #{$num}.", "Clothes for order #{$num} have been picked up.",
            'order_picked_up',
        );
        $this->notifications->sendToOrderDrivers($order, 'pickup',
            'تم الاستلام', 'Pickup Completed',
            "تم استلام الطلب #{$num}.", "Order #{$num} has been picked up.",
            'order_picked_up',
        );
    }

    private function onDeliveredToBranch(Order $order, string $num): void
    {
        $this->notifications->sendToClient($order,
            'وصل طلبك للمغسلة', 'Clothes at the Laundry',
            "وصل طلبك #{$num} إلى المغسلة وجاري معالجتها.",
            "Clothes for your order #{$num} have arrived at the laundry and are being processed.",
            'delivered_to_branch',
        );
        $this->notifyVendorAndAdmins($order,
            'تم تسليم الطلب للفرع', 'Clothes Delivered to Branch',
            "تم تسليم الطلب #{$num} للفرع.", "Clothes for order #{$num} have been delivered to the branch.",
            'delivered_to_branch',
        );
        $this->notifications->sendToOrderDrivers($order, 'pickup',
            'تم التسليم للفرع', 'Delivered to Branch',
            "تم تسليم الطلب #{$num} للفرع.", "Order #{$num} was delivered to the branch.",
            'delivered_to_branch',
        );
    }

    private function onDriverDeliveryAssigned(Order $order, string $num): void
    {
        $this->notifications->sendToClient($order,
            'تم تعيين سائق التوصيل', 'Delivery Driver Assigned',
            "تم تعيين سائق لتوصيل طلبك #{$num}.", "A driver has been assigned to deliver your order #{$num}.",
            'driver_delivery_assigned',
        );
        $this->notifyVendorAndAdmins($order,
            'تم تعيين سائق التوصيل', 'Delivery Driver Assigned',
            "تم تعيين سائق توصيل للطلب #{$num}.", "A delivery driver was assigned to order #{$num}.",
            'driver_delivery_assigned',
        );
    }

    private function onDriverDeliveryAccepted(Order $order, string $num): void
    {
        $this->notifications->sendToClient($order,
            'تم قبول التوصيل', 'Delivery Driver Accepted',
            "قبل السائق توصيل طلبك #{$num}.", "A driver has accepted to deliver your order #{$num}.",
            'driver_delivery_accepted',
        );
        $this->notifyVendorAndAdmins($order,
            'قبل سائق التوصيل', 'Delivery Driver Accepted',
            "قبل سائق توصيل الطلب #{$num}.", "Delivery driver accepted order #{$num}.",
            'driver_delivery_accepted',
        );
    }

    private function onWaitingClientReceipt(Order $order, string $num): void
    {
        $this->notifications->sendToClient($order,
            'السائق في موقع التسليم', 'Driver Has Arrived',
            "وصل السائق لموقع تسليم طلبك #{$num}. يرجى استلام الطلب.",
            "The driver has arrived with your order #{$num}. Please receive your order.",
            'waiting_client_receipt',
        );
        $this->notifyVendorAndAdmins($order,
            'السائق في موقع التسليم', 'Driver at Delivery Location',
            "وصل السائق لموقع تسليم الطلب #{$num}.", "Driver arrived at delivery location for order #{$num}.",
            'waiting_client_receipt',
        );
        $this->notifications->sendToOrderDrivers($order, 'delivery',
            'في انتظار العميل', 'Waiting for Client',
            "أنت في موقع تسليم الطلب #{$num} بانتظار العميل.", "You are at the delivery location for order #{$num}, waiting for the client.",
            'waiting_client_receipt',
        );
    }

    private function onDelivered(Order $order, string $num): void
    {
        $this->notifications->sendToClient($order,
            'تم التوصيل', 'Order Delivered',
            "تم توصيل طلبك #{$num}.", "Your order #{$num} has been delivered.",
            'order_delivered',
        );
        $this->notifyVendorAndAdmins($order,
            'تم التوصيل', 'Order Delivered',
            "تم توصيل الطلب #{$num}.", "Order #{$num} has been delivered.",
            'order_delivered',
        );
        $this->notifications->sendToOrderDrivers($order, 'delivery',
            'تم التوصيل', 'Delivery Completed',
            "تم توصيل الطلب #{$num}.", "Order #{$num} has been delivered.",
            'order_delivered',
        );
    }

    private function onClientPostponedPickup(Order $order, string $num): void
    {
        $this->notifyVendorAndAdmins($order,
            'تأجيل موعد الاستلام', 'Pickup Postponed',
            "أجل العميل موعد استلام الطلب #{$num}.", "Client postponed pickup for order #{$num}.",
            'client_postponed_pickup',
        );
        $this->notifications->sendToOrderDrivers($order, 'pickup',
            'تأجيل موعد الاستلام', 'Pickup Postponed',
            "أجل العميل موعد استلام الطلب #{$num}.", "Client postponed pickup for order #{$num}.",
            'client_postponed_pickup',
        );
    }

    private function onClientPostponedDelivery(Order $order, string $num): void
    {
        $this->notifyVendorAndAdmins($order,
            'تأجيل موعد التسليم', 'Delivery Postponed',
            "أجل العميل موعد تسليم الطلب #{$num}.", "Client postponed delivery for order #{$num}.",
            'client_postponed_delivery',
        );
        $this->notifications->sendToOrderDrivers($order, 'delivery',
            'تأجيل موعد التسليم', 'Delivery Postponed',
            "أجل العميل موعد تسليم الطلب #{$num}.", "Client postponed delivery for order #{$num}.",
            'client_postponed_delivery',
        );
    }

    private function onCompleted(Order $order, string $num): void
    {
        $this->notifications->sendToClient($order,
            'تم إكمال الطلب', 'Order Completed',
            "تم إكمال طلبك #{$num}. يرجى تقييم الخدمة.",
            "Your order #{$num} is completed. Please rate the service.",
            'order_completed',
        );
        $this->notifyVendorAndAdmins($order,
            'تم إكمال الطلب', 'Order Completed',
            "تم إكمال الطلب #{$num}.", "Order #{$num} has been completed.",
            'order_completed',
        );
    }

    private function onCancelled(Order $order, string $num): void
    {
        $this->notifications->sendToClient($order,
            'تم إلغاء الطلب', 'Order Cancelled',
            "تم إلغاء طلبك #{$num}.", "Your order #{$num} has been cancelled.",
            'order_cancelled',
        );
        $this->notifyVendorAndAdmins($order,
            'تم إلغاء الطلب', 'Order Cancelled',
            "تم إلغاء الطلب #{$num}.", "Order #{$num} has been cancelled.",
            'order_cancelled',
        );
        $this->notifications->sendToOrderDrivers($order, 'both',
            'تم إلغاء الطلب', 'Order Cancelled',
            "تم إلغاء الطلب #{$num}.", "Order #{$num} has been cancelled.",
            'order_cancelled',
        );
    }

    private function onDriverOnTheWayToClient(Order $order, string $leg): void
    {
        app(ClientDriverOnTheWayNotificationService::class)->send($order, $leg);

        $num = $order->order_number;
        $driverLeg = $leg === 'pickup' ? 'pickup' : 'delivery';

        $this->notifyVendorAndAdmins($order,
            $leg === 'pickup' ? 'السائق في الطريق للاستلام' : 'السائق في الطريق للتوصيل',
            $leg === 'pickup' ? 'Driver On the Way to Pickup' : 'Driver On the Way to Delivery',
            $leg === 'pickup'
                ? "السائق في الطريق لاستلام الطلب #{$num}."
                : "السائق في الطريق لتوصيل الطلب #{$num}.",
            $leg === 'pickup'
                ? "Driver is on the way to pick up order #{$num}."
                : "Driver is on the way to deliver order #{$num}.",
            $leg === 'pickup' ? 'driver_on_the_way_pickup' : 'driver_on_the_way_delivery',
        );

        $this->notifications->sendToOrderDrivers($order, $driverLeg,
            'أنت في الطريق', 'You Are On the Way',
            $leg === 'pickup'
                ? "أنت في الطريق لاستلام الطلب #{$num}."
                : "أنت في الطريق لتوصيل الطلب #{$num}.",
            $leg === 'pickup'
                ? "You are on the way to pick up order #{$num}."
                : "You are on the way to deliver order #{$num}.",
            $leg === 'pickup' ? 'driver_on_the_way_pickup' : 'driver_on_the_way_delivery',
        );
    }

    private function notifyVendorAndAdmins(
        Order $order,
        string $titleAr,
        string $titleEn,
        string $bodyAr,
        string $bodyEn,
        string $type,
        array $extraData = []
    ): void {
        $this->notifications->sendToVendorAndAdmins(
            $order,
            $titleAr,
            $titleEn,
            $bodyAr,
            $bodyEn,
            $type,
            $extraData
        );
    }
}
