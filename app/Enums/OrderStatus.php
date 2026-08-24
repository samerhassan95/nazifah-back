<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDING = 'pending';
    case BRANCH_REVIEW = 'branch_review';
    case CONFIRMED = 'confirmed';
    case WAITING_PAYMENT = 'waiting_payment';
    case PAYMENT_CONFIRMED = 'payment_confirmed';
    case AWAITING_REMAINING_PAYMENT = 'awaiting_remaining_payment';
    case DRIVER_PICKUP_ASSIGNED = 'driver_pickup_assigned';
    case DRIVER_PICKUP_ACCEPTED = 'driver_pickup_accepted';
    case ON_WAY_TO_PICKUP = 'on_way_to_pickup';
    case PICKED_UP = 'picked_up';

    case DELIVERED_TO_BRANCH = 'delivered_to_branch';
    
    case DRIVER_DELIVERY_ASSIGNED = 'driver_delivery_assigned';
    case DRIVER_DELIVERY_ACCEPTED = 'driver_delivery_accepted';
    case ON_WAY_TO_DELIVERY = 'on_way_to_delivery';
    case WAITING_CLIENT_RECEIPT = 'waiting_client_receipt';
    case DELIVERED = 'delivered';
    case CLIENT_POSTPONED_PICKUP = 'client_postponed_pickup';
    case CLIENT_POSTPONED_DELIVERY = 'client_postponed_delivery';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function fromString(string $value): ?self
    {
        return self::tryFrom($value);
    }

    public static function newOrderStatuses(): array
    {
        return [self::PENDING->value];
    }

    public static function isNewOrderStatus(string $status): bool
    {
        return in_array($status, self::newOrderStatuses(), true);
    }

    // ------------------------------------------------------------------
    // Labels
    // ------------------------------------------------------------------

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::BRANCH_REVIEW => 'Branch Review',
            self::CONFIRMED => 'Confirmed',
            self::WAITING_PAYMENT => 'Waiting Payment',
            self::PAYMENT_CONFIRMED => 'Payment Confirmed',
            self::AWAITING_REMAINING_PAYMENT => 'Awaiting Remaining Payment',
            self::DRIVER_PICKUP_ASSIGNED => 'Pickup Driver Assigned',
            self::DRIVER_PICKUP_ACCEPTED => 'Pickup Driver Accepted',
            self::ON_WAY_TO_PICKUP => 'On Way to Pickup',
            self::PICKED_UP => 'Picked Up',
            self::DELIVERED_TO_BRANCH => 'Delivered to Branch',
            self::DRIVER_DELIVERY_ASSIGNED => 'Delivery Driver Assigned',
            self::DRIVER_DELIVERY_ACCEPTED => 'Delivery Driver Accepted',
            self::ON_WAY_TO_DELIVERY => 'On Way to Delivery',
            self::WAITING_CLIENT_RECEIPT => 'At Delivery Location - Waiting for Client',
            self::DELIVERED => 'Delivered',
            self::CLIENT_POSTPONED_PICKUP => 'Client Postponed Pickup',
            self::CLIENT_POSTPONED_DELIVERY => 'Client Postponed Delivery',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::PENDING => 'قيد الانتظار',
            self::BRANCH_REVIEW => 'تم مراجعة الفرع',
            self::CONFIRMED => 'مؤكد',
            self::WAITING_PAYMENT => 'في انتظار الدفع',
            self::PAYMENT_CONFIRMED => 'تم تأكيد الدفع',
            self::AWAITING_REMAINING_PAYMENT => 'في انتظار سداد المبلغ المتبقي',
            self::DRIVER_PICKUP_ASSIGNED => 'تم تعيين سائق الاستلام',
            self::DRIVER_PICKUP_ACCEPTED => 'قبل سائق الاستلام',
            self::ON_WAY_TO_PICKUP => 'في الطريق للاستلام',
            self::PICKED_UP => 'تم الاستلام',
            self::DELIVERED_TO_BRANCH => 'تم التسليم للفرع',
            self::DRIVER_DELIVERY_ASSIGNED => 'تم تعيين سائق التوصيل',
            self::DRIVER_DELIVERY_ACCEPTED => 'قبل سائق التوصيل',
            self::ON_WAY_TO_DELIVERY => 'في الطريق للتوصيل',
            self::WAITING_CLIENT_RECEIPT => 'تم الوصول لموقع التسليم في انتظار استلام العميل',
            self::DELIVERED => 'تم التوصيل',
            self::CLIENT_POSTPONED_PICKUP => 'أجل العميل موعد الاستلام',
            self::CLIENT_POSTPONED_DELIVERY => 'أجل العميل موعد التسليم',
            self::COMPLETED => 'مكتمل',
            self::CANCELLED => 'ملغي',
        };
    }

    public function localizedLabel(?string $paymentMethod = null, bool $awaitingClientReceipt = false): string
    {
        $isAr = app()->getLocale() === 'ar';

        if ($this === self::PAYMENT_CONFIRMED
            && $paymentMethod === \App\Enums\PaymentMethod::CASH_ON_DELIVERY->value) {
            return $isAr ? 'تم تأكيد الطلب' : 'Order Confirmed';
        }

        // COMPLETED means the vendor's side is done — but the client hasn't actually
        // received the order yet (branch self-pickup not collected, or home delivery
        // not yet handed over). Show that it's still on them, not "Completed".
        if ($this === self::COMPLETED && $awaitingClientReceipt) {
            return $isAr ? 'جاهز - في انتظار استلامك' : 'Ready — Awaiting Your Receipt';
        }

        return $isAr ? $this->labelAr() : $this->label();
    }

    // ------------------------------------------------------------------
    // UI helpers
    // ------------------------------------------------------------------

    public function color(): string
    {
        return match ($this) {
            self::PENDING => '#FFA500',
            self::BRANCH_REVIEW => '#8A2BE2',
            self::CONFIRMED => '#4169E1',
            self::WAITING_PAYMENT => '#DAA520',
            self::PAYMENT_CONFIRMED => '#2E8B57',
            self::AWAITING_REMAINING_PAYMENT => '#E67E22',
            self::DRIVER_PICKUP_ASSIGNED => '#9B59B6',
            self::DRIVER_PICKUP_ACCEPTED => '#3498DB',
            self::ON_WAY_TO_PICKUP => '#87CEEB',
            self::PICKED_UP => '#1E90FF',
            self::DELIVERED_TO_BRANCH => '#4682B4',
            self::DRIVER_DELIVERY_ASSIGNED => '#9B59B6',
            self::DRIVER_DELIVERY_ACCEPTED => '#3498DB',
            self::ON_WAY_TO_DELIVERY => '#FFD700',
            self::WAITING_CLIENT_RECEIPT => '#DAA520',
            self::DELIVERED => '#228B22',
            self::CLIENT_POSTPONED_PICKUP => '#CD853F',
            self::CLIENT_POSTPONED_DELIVERY => '#CD853F',
            self::COMPLETED => '#008000',
            self::CANCELLED => '#DC143C',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::PENDING => '⏳',
            self::BRANCH_REVIEW => '📝',
            self::CONFIRMED => '✓',
            self::WAITING_PAYMENT => '💳',
            self::PAYMENT_CONFIRMED => '✅',
            self::AWAITING_REMAINING_PAYMENT => '💰',
            self::DRIVER_PICKUP_ASSIGNED => '👤',
            self::DRIVER_PICKUP_ACCEPTED => '🤝',
            self::ON_WAY_TO_PICKUP => '🚗',
            self::PICKED_UP => '📦',
            self::DELIVERED_TO_BRANCH => '🏪',
            self::DRIVER_DELIVERY_ASSIGNED => '👤',
            self::DRIVER_DELIVERY_ACCEPTED => '🤝',
            self::ON_WAY_TO_DELIVERY => '🚚',
            self::WAITING_CLIENT_RECEIPT => '📍',
            self::DELIVERED => '📬',
            self::CLIENT_POSTPONED_PICKUP => '📅',
            self::CLIENT_POSTPONED_DELIVERY => '📅',
            self::COMPLETED => '🎉',
            self::CANCELLED => '❌',
        };
    }

    // ------------------------------------------------------------------
    // State queries
    // ------------------------------------------------------------------

    public function isCurrent(): bool
    {
        return in_array($this->value, self::vendorCurrentStatusValues(), true);
    }

    public function isCompleted(): bool
    {
        return in_array($this, [self::DELIVERED], true);
    }

    public function isCancelled(): bool
    {
        return $this === self::CANCELLED;
    }

    public function canBeCancelled(): bool
    {
        return in_array($this, [
            self::PENDING,
            self::BRANCH_REVIEW,
            self::CONFIRMED,
            self::DRIVER_PICKUP_ASSIGNED,
            self::DRIVER_PICKUP_ACCEPTED,
            self::WAITING_PAYMENT,
            self::AWAITING_REMAINING_PAYMENT,
        ], true);
    }

    public function canBeRated(): bool
    {
        return in_array($this, [self::DELIVERED, self::COMPLETED], true);
    }

    // ------------------------------------------------------------------
    // Transition rules — the SINGLE source of truth
    // ------------------------------------------------------------------

    public function nextStatuses(bool $pickupAtVendor, bool $deliveryAtVendor): array
    {
        return match ($this) {
            // BRANCH_REVIEW: normal flow — the laundry reviews the order.
            // PAYMENT_CONFIRMED: prepaid checkout (PURCHASE command) where the
            // payment was captured up front, so the order is created already paid
            // and the gateway callback confirms it directly.
            self::PENDING => [self::BRANCH_REVIEW, self::PAYMENT_CONFIRMED, self::CANCELLED],

            self::BRANCH_REVIEW => [self::CONFIRMED, self::CANCELLED],

            self::CONFIRMED => $pickupAtVendor
                ? [self::WAITING_PAYMENT, self::DELIVERED_TO_BRANCH, self::CLIENT_POSTPONED_PICKUP, self::CANCELLED, self::AWAITING_REMAINING_PAYMENT]
                : [self::DRIVER_PICKUP_ASSIGNED, self::CANCELLED, self::AWAITING_REMAINING_PAYMENT],

            self::DRIVER_PICKUP_ASSIGNED => [self::DRIVER_PICKUP_ACCEPTED, self::ON_WAY_TO_PICKUP, self::CLIENT_POSTPONED_PICKUP, self::CANCELLED],

            self::DRIVER_PICKUP_ACCEPTED => [
                self::DRIVER_PICKUP_ASSIGNED,
                self::ON_WAY_TO_PICKUP,
                self::PICKED_UP,
                self::WAITING_PAYMENT,
                self::CLIENT_POSTPONED_PICKUP,
                self::CANCELLED,
            ],

            self::WAITING_PAYMENT => $pickupAtVendor
                ? [self::PAYMENT_CONFIRMED, self::DELIVERED_TO_BRANCH, self::CANCELLED, self::AWAITING_REMAINING_PAYMENT]
                : [self::PAYMENT_CONFIRMED, self::CANCELLED, self::AWAITING_REMAINING_PAYMENT],

            self::PAYMENT_CONFIRMED => $pickupAtVendor
                ? [self::PICKED_UP, self::DELIVERED_TO_BRANCH, self::CLIENT_POSTPONED_PICKUP, self::AWAITING_REMAINING_PAYMENT]
                : [self::DRIVER_PICKUP_ASSIGNED, self::ON_WAY_TO_PICKUP, self::PICKED_UP, self::DELIVERED_TO_BRANCH, self::CLIENT_POSTPONED_PICKUP, self::AWAITING_REMAINING_PAYMENT],

            // Confirmation-time capture could not cover the full amount due (Phase-2
            // Case B). From here the customer settles the remaining amount
            // (-> PAYMENT_CONFIRMED) or the order is cancelled.
            self::AWAITING_REMAINING_PAYMENT => [self::PAYMENT_CONFIRMED, self::CANCELLED],

            self::ON_WAY_TO_PICKUP => [self::DRIVER_PICKUP_ASSIGNED, self::PICKED_UP, self::CLIENT_POSTPONED_PICKUP],

            self::PICKED_UP => $pickupAtVendor
                ? ($deliveryAtVendor
                    ? [self::DELIVERED_TO_BRANCH, self::COMPLETED]
                    : [self::COMPLETED, self::DELIVERED])
                : [self::DELIVERED_TO_BRANCH],

            self::DELIVERED_TO_BRANCH => $deliveryAtVendor
                ? [self::COMPLETED, self::CLIENT_POSTPONED_DELIVERY]
                : [self::DRIVER_DELIVERY_ASSIGNED, self::DELIVERED, self::COMPLETED, self::CLIENT_POSTPONED_DELIVERY],

            self::DRIVER_DELIVERY_ASSIGNED => [self::DRIVER_DELIVERY_ACCEPTED, self::ON_WAY_TO_DELIVERY, self::CLIENT_POSTPONED_DELIVERY],

            self::DRIVER_DELIVERY_ACCEPTED => [self::DRIVER_DELIVERY_ASSIGNED, self::ON_WAY_TO_DELIVERY, self::CLIENT_POSTPONED_DELIVERY],

            self::ON_WAY_TO_DELIVERY => [self::DRIVER_DELIVERY_ASSIGNED, self::WAITING_CLIENT_RECEIPT, self::DELIVERED, self::CLIENT_POSTPONED_DELIVERY],

            self::WAITING_CLIENT_RECEIPT => $deliveryAtVendor
                ? [self::DRIVER_DELIVERY_ASSIGNED, self::DELIVERED, self::CLIENT_POSTPONED_DELIVERY]
                : [self::DRIVER_DELIVERY_ASSIGNED, self::DELIVERED, self::COMPLETED, self::CLIENT_POSTPONED_DELIVERY],

            self::DELIVERED => $deliveryAtVendor
                ? [self::COMPLETED, self::CLIENT_POSTPONED_DELIVERY]
                : [self::COMPLETED, self::CLIENT_POSTPONED_DELIVERY],

            self::CLIENT_POSTPONED_PICKUP => $pickupAtVendor
                ? [self::PAYMENT_CONFIRMED, self::CONFIRMED]
                : [self::DRIVER_PICKUP_ASSIGNED, self::DRIVER_PICKUP_ACCEPTED, self::ON_WAY_TO_PICKUP, self::PAYMENT_CONFIRMED],

            self::CLIENT_POSTPONED_DELIVERY => $deliveryAtVendor
                ? [self::DELIVERED_TO_BRANCH, self::COMPLETED]
                : [self::DRIVER_DELIVERY_ASSIGNED, self::DRIVER_DELIVERY_ACCEPTED, self::ON_WAY_TO_DELIVERY, self::DELIVERED_TO_BRANCH],

            self::COMPLETED => $deliveryAtVendor
                ? [self::DELIVERED, self::CLIENT_POSTPONED_DELIVERY]
                : [self::DRIVER_DELIVERY_ASSIGNED, self::DELIVERED],

            self::CANCELLED => [],
        };
    }

    // ------------------------------------------------------------------
    // Static collections
    // ------------------------------------------------------------------

    public static function currentStatuses(): array
    {
        return array_filter(
            self::cases(),
            fn (self $s) => in_array($s->value, self::vendorCurrentStatusValues(), true)
        );
    }

    public static function completedStatuses(): array
    {
        return [self::DELIVERED, self::COMPLETED];
    }

    /**
     * In-progress statuses for vendor "current" tab (excludes pending and finished).
     *
     * @return list<string>
     */
    public static function vendorCurrentStatusValues(): array
    {
        return [
            self::BRANCH_REVIEW->value,
            self::CONFIRMED->value,
            self::WAITING_PAYMENT->value,
            self::PAYMENT_CONFIRMED->value,
            self::DRIVER_PICKUP_ASSIGNED->value,
            self::DRIVER_PICKUP_ACCEPTED->value,
            self::ON_WAY_TO_PICKUP->value,
            self::PICKED_UP->value,
            self::DELIVERED_TO_BRANCH->value,
            self::DRIVER_DELIVERY_ASSIGNED->value,
            self::DRIVER_DELIVERY_ACCEPTED->value,
            self::ON_WAY_TO_DELIVERY->value,
            self::WAITING_CLIENT_RECEIPT->value,
            self::CLIENT_POSTPONED_PICKUP->value,
            self::CLIENT_POSTPONED_DELIVERY->value,
        ];
    }

    public static function forClient(): array
    {
        return self::cases();
    }

    /**
     * Statuses where vendor may assign or re-assign a pickup driver (home pickup orders).
     *
     * @return list<OrderStatus>
     */
    public static function vendorPickupDriverAssignableStatuses(): array
    {
        return [
            self::CONFIRMED,
            self::PAYMENT_CONFIRMED,
            self::DRIVER_PICKUP_ASSIGNED,
            self::DRIVER_PICKUP_ACCEPTED,
            self::ON_WAY_TO_PICKUP,
            self::CLIENT_POSTPONED_PICKUP,
        ];
    }

    /**
     * Statuses where vendor may assign or re-assign a delivery driver (home delivery orders).
     *
     * @return list<OrderStatus>
     */
    public static function vendorDeliveryDriverAssignableStatuses(): array
    {
        return [
            self::DELIVERED_TO_BRANCH,
            self::COMPLETED,
            self::DRIVER_DELIVERY_ASSIGNED,
            self::DRIVER_DELIVERY_ACCEPTED,
            self::ON_WAY_TO_DELIVERY,
            self::WAITING_CLIENT_RECEIPT,
            self::CLIENT_POSTPONED_DELIVERY,
        ];
    }

    public static function forVendor(): array
    {
        return [
            self::PENDING,
            self::BRANCH_REVIEW,
            self::CONFIRMED,
            self::PAYMENT_CONFIRMED,
            self::PICKED_UP,
            self::DELIVERED_TO_BRANCH,
            self::CLIENT_POSTPONED_PICKUP,
            self::CLIENT_POSTPONED_DELIVERY,
            self::COMPLETED,
            self::CANCELLED,
        ];
    }

    /**
     * Statuses where the client can track driver location / delivery progress.
     *
     * @return list<string>
     */
    public static function onTheWayTrackingStatusValues(): array
    {
        return [
            self::ON_WAY_TO_PICKUP->value,
            self::PICKED_UP->value,
            self::DELIVERED_TO_BRANCH->value,
            self::ON_WAY_TO_DELIVERY->value,
            self::WAITING_CLIENT_RECEIPT->value,
            self::DELIVERED->value,
        ];
    }

    /**
     * Pickup leg: client may confirm readiness or postpone (reject) the visit.
     *
     * @return list<string>
     */
    public static function clientPickupVisitResponseStatusValues(): array
    {
        return [
            self::ON_WAY_TO_PICKUP->value,
        ];
    }

    /**
     * Delivery leg: client may confirm readiness or postpone (reject) the visit.
     *
     * @return list<string>
     */
    public static function clientDeliveryVisitResponseStatusValues(): array
    {
        return [
            self::ON_WAY_TO_DELIVERY->value,
        ];
    }

    /**
     * Client drops off laundry at the branch (pickup_at_vendor).
     *
     * @return list<string>
     */
    public static function clientBranchDropoffVisitResponseStatusValues(): array
    {
        return [
            self::CONFIRMED->value,
            self::PAYMENT_CONFIRMED->value,
            self::CLIENT_POSTPONED_PICKUP->value,
        ];
    }

    /**
     * Client picks up laundry from the branch (delivery_at_vendor).
     *
     * @return list<string>
     */
    public static function clientBranchPickupVisitResponseStatusValues(): array
    {
        return [
            self::COMPLETED->value,
            self::DELIVERED->value,
            self::CLIENT_POSTPONED_DELIVERY->value,
        ];
    }

    /**
     * Client confirms final receipt after driver delivery.
     *
     * @return list<string>
     */
    public static function clientReceiptConfirmStatusValues(): array
    {
        return [
            self::WAITING_CLIENT_RECEIPT->value,
            self::DELIVERED->value,
        ];
    }

    public static function isPickupVisitResponseStatus(string $status): bool
    {
        return in_array($status, self::clientPickupVisitResponseStatusValues(), true);
    }

    public static function isDeliveryVisitResponseStatus(string $status): bool
    {
        return in_array($status, self::clientDeliveryVisitResponseStatusValues(), true);
    }

    public static function isBranchDropoffVisitResponseStatus(string $status): bool
    {
        return in_array($status, self::clientBranchDropoffVisitResponseStatusValues(), true);
    }

    public static function isBranchPickupVisitResponseStatus(string $status): bool
    {
        return in_array($status, self::clientBranchPickupVisitResponseStatusValues(), true);
    }

    public static function isReceiptConfirmStatus(string $status): bool
    {
        return in_array($status, self::clientReceiptConfirmStatusValues(), true);
    }

    /**
     * Statuses shown on the client "driver visit" tracking card (assigned or en route).
     *
     * @return list<string>
     */
    public static function clientDriverVisitTrackingStatusValues(): array
    {
        return array_values(array_unique(array_merge(
            self::onTheWayTrackingStatusValues(),
            self::clientPickupVisitResponseStatusValues(),
            self::clientDeliveryVisitResponseStatusValues(),
            self::clientBranchDropoffVisitResponseStatusValues(),
            self::clientBranchPickupVisitResponseStatusValues(),
            self::clientReceiptConfirmStatusValues(),
            self::clientHandoffTrackingStatusValues(),
        )));
    }

    /**
     * Statuses where the client may need to confirm actual give/receive handoff.
     *
     * @return list<string>
     */
    public static function clientHandoffTrackingStatusValues(): array
    {
        return [
            self::CONFIRMED->value,
            self::PAYMENT_CONFIRMED->value,
            self::DRIVER_PICKUP_ASSIGNED->value,
            self::DRIVER_PICKUP_ACCEPTED->value,
            self::ON_WAY_TO_PICKUP->value,
            self::PICKED_UP->value,
            self::ON_WAY_TO_DELIVERY->value,
            self::WAITING_CLIENT_RECEIPT->value,
            self::DELIVERED->value,
        ];
    }

    /**
     * Statuses where the client may still edit order items — any point before
     * the driver accepts the pickup.
     *
     * @return list<string>
     */
    public static function clientEditableStatusValues(): array
    {
        return [
            self::PENDING->value,
            self::BRANCH_REVIEW->value,
            self::CONFIRMED->value,
            self::WAITING_PAYMENT->value,
            self::PAYMENT_CONFIRMED->value,
            self::AWAITING_REMAINING_PAYMENT->value,
            self::DRIVER_PICKUP_ASSIGNED->value,
        ];
    }

    public static function isClientEditable(string $status): bool
    {
        return in_array($status, self::clientEditableStatusValues(), true);
    }

    public static function forDriver(): array
    {
        return [
            self::DRIVER_PICKUP_ASSIGNED,
            self::DRIVER_PICKUP_ACCEPTED,
            self::ON_WAY_TO_PICKUP,
            self::PICKED_UP,
            self::DELIVERED_TO_BRANCH,
            self::DRIVER_DELIVERY_ASSIGNED,
            self::DRIVER_DELIVERY_ACCEPTED,
            self::ON_WAY_TO_DELIVERY,
            self::WAITING_CLIENT_RECEIPT,
            self::DELIVERED,
            self::CLIENT_POSTPONED_PICKUP,
            self::CLIENT_POSTPONED_DELIVERY,
            self::COMPLETED,
        ];
    }
}
