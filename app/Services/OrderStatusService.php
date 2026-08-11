<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Events\DriverAssigned;
use App\Events\OrderStatusChanged;
use App\Exceptions\InvalidStatusTransitionException;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Models\Driver;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDriverTrip;
use Modules\Order\Models\OrderStatusLog;

class OrderStatusService
{
    /**
     * Transition an order to a new status.
     * Validates the transition, persists the change, logs it, and fires events.
     */
    public function transitionTo(Order $order, OrderStatus $newStatus, array $context = []): Order
    {
        $oldStatus = OrderStatus::from($order->status);

        if (! $this->canTransition($order, $newStatus)) {
            throw new InvalidStatusTransitionException($oldStatus, $newStatus, $order);
        }

        // If already at the target status, return early (idempotent)
        if ($oldStatus === $newStatus) {
            return $order;
        }

        // Home delivery: laundry must confirm handoff before driver can mark on_way_to_delivery.
        if (
            $newStatus === OrderStatus::ON_WAY_TO_DELIVERY
            && $order->needsDeliveryDriver()
            && in_array($oldStatus, [
                OrderStatus::DRIVER_DELIVERY_ASSIGNED,
                OrderStatus::DRIVER_DELIVERY_ACCEPTED,
                OrderStatus::CLIENT_POSTPONED_DELIVERY,
            ], true)
            && $order->vendor_handed_to_delivery_at === null
        ) {
            throw new \LogicException(__('order.driver_on_way_requires_laundry_handoff'));
        }


        $updateData = ['status' => $newStatus->value];

        if ($newStatus === OrderStatus::ON_WAY_TO_DELIVERY) {
            $updateData['client_delivery_visit_confirmed_at'] = null;
        }

        if ($newStatus === OrderStatus::WAITING_CLIENT_RECEIPT) {
            $updateData['client_delivery_handoff_at'] = null;
        }

        if (in_array($newStatus, [
            OrderStatus::DRIVER_PICKUP_ASSIGNED,
            OrderStatus::CLIENT_POSTPONED_PICKUP,
        ], true)) {
            $updateData['client_pickup_visit_confirmed_at'] = null;
            $updateData['driver_pickup_notified_client_at'] = null;
        }

        if (in_array($newStatus, [
            OrderStatus::DRIVER_DELIVERY_ASSIGNED,
            OrderStatus::CLIENT_POSTPONED_DELIVERY,
        ], true)) {
            $updateData['client_delivery_visit_confirmed_at'] = null;
        }

        if (in_array($newStatus, [
            OrderStatus::ON_WAY_TO_PICKUP,
            OrderStatus::PICKED_UP,
            OrderStatus::CANCELLED,
        ], true)) {
            $updateData['driver_pickup_notified_client_at'] = null;
        }

        if ($newStatus === OrderStatus::CANCELLED) {
            $updateData['cancelled_reason'] = $context['reason'] ?? null;
            $updateData['cancelled_at'] = now();
        }

        if ($newStatus === OrderStatus::DELIVERED) {
            $updateData['actual_delivery_time'] = now();
        }

        $order->update($updateData);

        OrderStatusLog::create([
            'order_id' => $order->id,
            'status' => $newStatus->value,
            'notes' => $context['notes'] ?? __('order.status_log_default_transition', [
                'from' => $oldStatus->localizedLabel(),
                'to' => $newStatus->localizedLabel(),
            ]),
            'changed_by' => $context['changed_by'] ?? null,
        ]);

        $this->dispatchEvent(new OrderStatusChanged($order, $oldStatus, $newStatus, $context));

        $this->recordDriverTripIfCompleted($order, $newStatus);

        $this->handleAutoTransitions($order, $newStatus, $context);

        return $order;
    }

    /**
     * Record a completed driver trip (pickup or delivery) for reports and driver stats.
     */
    protected function recordDriverTripIfCompleted(Order $order, OrderStatus $newStatus): void
    {
        if ($newStatus === OrderStatus::DELIVERED_TO_BRANCH && $order->pickup_driver_id) {
            $driverId = (int) $order->pickup_driver_id;
            $fee = $this->tripFeeForDriver($order, $driverId, 'pickup');
            OrderDriverTrip::updateOrCreate(
                [
                    'order_id' => $order->id,
                    'driver_id' => $driverId,
                    'trip_type' => OrderDriverTrip::TYPE_PICKUP,
                ],
                [
                    'status' => OrderDriverTrip::STATUS_COMPLETED,
                    'completed_at' => now(),
                    'delivery_fee' => $fee,
                ]
            );
        }

        if ($newStatus === OrderStatus::DELIVERED && $order->delivery_driver_id) {
            $driverId = (int) $order->delivery_driver_id;
            $fee = $this->tripFeeForDriver($order, $driverId, 'delivery');
            OrderDriverTrip::updateOrCreate(
                [
                    'order_id' => $order->id,
                    'driver_id' => $driverId,
                    'trip_type' => OrderDriverTrip::TYPE_DELIVERY,
                ],
                [
                    'status' => OrderDriverTrip::STATUS_COMPLETED,
                    'completed_at' => now(),
                    'delivery_fee' => $fee,
                ]
            );
        }
    }

    /**
     * Fee amount for this driver for this leg (pickup or delivery) for reporting.
     */
    protected function tripFeeForDriver(Order $order, int $driverId, string $tripType): float
    {
        $total = (float) $order->delivery_fee;
        if ($total <= 0) {
            return 0.0;
        }
        $isPickup = (int) $order->pickup_driver_id === $driverId;
        $isDelivery = (int) $order->delivery_driver_id === $driverId;
        $hasTwoDrivers = $order->pickup_driver_id && $order->delivery_driver_id
            && (int) $order->pickup_driver_id !== (int) $order->delivery_driver_id;
        if ($hasTwoDrivers) {
            return round($total / 2, 2);
        }

        return $total;
    }

    public function canTransition(Order $order, OrderStatus $newStatus): bool
    {
        $currentStatus = OrderStatus::from($order->status);

        // Allow idempotent transitions (same status)
        if ($currentStatus === $newStatus) {
            return true;
        }

        $allowed = $currentStatus->nextStatuses(
            (bool) $order->pickup_at_vendor,
            (bool) $order->delivery_at_vendor
        );

        return in_array($newStatus, $allowed);
    }

    /**
     * Assign a pickup driver (home_pickup orders only).
     * First assignment transitions CONFIRMED → DRIVER_PICKUP_ASSIGNED.
     * Re-assignment after rejection stays at DRIVER_PICKUP_ASSIGNED.
     */
    public function assignPickupDriver(Order $order, Driver $driver, ?int $changedBy = null): Order
    {
        if ((bool) $order->pickup_at_vendor) {
            throw new \LogicException(__('driver.driver_assign_error_branch_dropoff'));
        }

        $currentStatus = OrderStatus::from($order->status);

        if ($currentStatus === OrderStatus::CANCELLED) {
            throw new \LogicException(__('driver.driver_assignment_failed'));
        }

        if (! in_array($currentStatus, OrderStatus::vendorPickupDriverAssignableStatuses(), true)) {
            throw new \LogicException(__('driver.driver_assign_error_pickup_status', [
                'status' => $currentStatus->localizedLabel(),
            ]));
        }

        $previousPickupDriverId = $order->pickup_driver_id;
        if ($previousPickupDriverId && (int) $previousPickupDriverId !== (int) $driver->id) {
            Driver::where('id', $previousPickupDriverId)->update(['is_available' => true]);
        }

        $order->update([
            'driver_id' => $driver->id,
            'pickup_driver_id' => $driver->id,
        ]);

        $pickupNotes = $currentStatus === OrderStatus::CLIENT_POSTPONED_PICKUP
            ? __('order.status_log_pickup_driver_assigned_postponed', ['id' => $driver->id])
            : ($currentStatus === OrderStatus::CONFIRMED
                ? __('order.status_log_pickup_driver_assigned', ['id' => $driver->id])
                : __('order.status_log_pickup_driver_reassigned_from', [
                    'status' => $currentStatus->localizedLabel(),
                    'id' => $driver->id,
                ]));

        if ($currentStatus === OrderStatus::DRIVER_PICKUP_ASSIGNED) {
            OrderStatusLog::create([
                'order_id' => $order->id,
                'status' => OrderStatus::DRIVER_PICKUP_ASSIGNED->value,
                'notes' => __('order.status_log_pickup_driver_reassigned', ['id' => $driver->id]),
                'changed_by' => $changedBy,
            ]);
        } elseif ($this->canTransition($order, OrderStatus::DRIVER_PICKUP_ASSIGNED)) {
            $this->transitionTo($order, OrderStatus::DRIVER_PICKUP_ASSIGNED, [
                'notes' => $pickupNotes,
                'changed_by' => $changedBy,
            ]);
        } else {
            OrderStatusLog::create([
                'order_id' => $order->id,
                'status' => $order->status,
                'notes' => $pickupNotes,
                'changed_by' => $changedBy,
            ]);
        }

        // Create Client ↔ Driver chat conversation when driver is assigned
        $chatService = app(\Modules\Chat\Services\ChatService::class);
        $chatService->ensureConversationForOrder(
            $order->id,
            $order->client_id,
            null, // vendor_id must be null for client-driver chat
            $driver->id
        );

        $this->dispatchEvent(new DriverAssigned($order, $driver, 'pickup', $previousPickupDriverId ? (int) $previousPickupDriverId : null));

        return $order;
    }

    /**
     * Assign a delivery driver (home_delivery orders only).
     * First assignment transitions DELIVERED_TO_BRANCH or COMPLETED → DRIVER_DELIVERY_ASSIGNED.
     * Re-assignment while still at DRIVER_DELIVERY_ASSIGNED logs a new assignment without changing status.
     */
    public function assignDeliveryDriver(Order $order, Driver $driver, ?int $changedBy = null): Order
    {
        if ((bool) $order->delivery_at_vendor) {
            throw new \LogicException(__('driver.driver_assign_error_branch_pickup'));
        }

        $currentStatus = OrderStatus::from($order->status);

        if ($currentStatus === OrderStatus::CANCELLED) {
            throw new \LogicException(__('driver.driver_assignment_failed'));
        }

        if (! in_array($currentStatus, OrderStatus::vendorDeliveryDriverAssignableStatuses(), true)) {
            throw new \LogicException(__('driver.driver_assign_error_delivery_status', [
                'status' => $currentStatus->localizedLabel(),
            ]));
        }

        $previousDeliveryDriverId = $order->delivery_driver_id;
        if ($previousDeliveryDriverId && (int) $previousDeliveryDriverId !== (int) $driver->id) {
            Driver::where('id', $previousDeliveryDriverId)->update(['is_available' => true]);
        }

        $order->update([
            'driver_id' => $driver->id,
            'delivery_driver_id' => $driver->id,
            'vendor_handed_to_delivery_at' => null,
            'can_confirm_handover_to_delivery' => false,
            'can_confirm_pickup_from_driver' => false,
        ]);

        $deliveryNotes = $currentStatus === OrderStatus::CLIENT_POSTPONED_DELIVERY
            ? __('order.status_log_delivery_driver_assigned_postponed', ['id' => $driver->id])
            : (in_array($currentStatus, [OrderStatus::DELIVERED_TO_BRANCH, OrderStatus::COMPLETED], true)
                ? __('order.status_log_delivery_driver_assigned', ['id' => $driver->id])
                : __('order.status_log_delivery_driver_reassigned_from', [
                    'status' => $currentStatus->localizedLabel(),
                    'id' => $driver->id,
                ]));

        if ($currentStatus === OrderStatus::DRIVER_DELIVERY_ASSIGNED) {
            OrderStatusLog::create([
                'order_id' => $order->id,
                'status' => OrderStatus::DRIVER_DELIVERY_ASSIGNED->value,
                'notes' => __('order.status_log_delivery_driver_reassigned', ['id' => $driver->id]),
                'changed_by' => $changedBy,
            ]);
        } elseif ($this->canTransition($order, OrderStatus::DRIVER_DELIVERY_ASSIGNED)) {
            $this->transitionTo($order, OrderStatus::DRIVER_DELIVERY_ASSIGNED, [
                'notes' => $deliveryNotes,
                'changed_by' => $changedBy,
            ]);
        } else {
            OrderStatusLog::create([
                'order_id' => $order->id,
                'status' => $order->status,
                'notes' => $deliveryNotes,
                'changed_by' => $changedBy,
            ]);
        }

        // Create Client ↔ Driver chat conversation when driver is assigned
        $chatService = app(\Modules\Chat\Services\ChatService::class);
        $chatService->ensureConversationForOrder(
            $order->id,
            $order->client_id,
            null, // vendor_id must be null for client-driver chat
            $driver->id
        );

        $this->dispatchEvent(new DriverAssigned($order, $driver, 'delivery', $previousDeliveryDriverId ? (int) $previousDeliveryDriverId : null));

        return $order;
    }

    /**
     * Handle a driver accepting or rejecting an assignment.
     *
     * accept → transitions to the *_ACCEPTED status and stays there
     *          (no auto-advance to waiting_payment / payment_confirmed).
     * reject → unassigns the driver, status stays at *_ASSIGNED (vendor must re-assign).
     */
    public function handleDriverResponse(Order $order, Driver $driver, string $response, ?string $reason = null): Order
    {
        $currentStatus = OrderStatus::from($order->status);

        if ($response === 'accept') {
            if ($currentStatus === OrderStatus::DRIVER_PICKUP_ASSIGNED && (int) $order->pickup_driver_id === (int) $driver->id) {
                return $this->transitionTo($order, OrderStatus::DRIVER_PICKUP_ACCEPTED, [
                    'notes' => __('order.status_log_pickup_driver_accepted', ['id' => $driver->id]),
                    'changed_by' => $driver->id,
                ]);
            }

            if ($currentStatus === OrderStatus::DRIVER_DELIVERY_ASSIGNED && (int) $order->delivery_driver_id === (int) $driver->id) {
                return $this->transitionTo($order, OrderStatus::DRIVER_DELIVERY_ACCEPTED, [
                    'notes' => __('order.status_log_delivery_driver_accepted', ['id' => $driver->id]),
                    'changed_by' => $driver->id,
                ]);
            }

            throw new \LogicException(__('driver.driver_response_error_cannot_accept', [
                'id' => $driver->id,
                'status' => $currentStatus->localizedLabel(),
            ]));
        }

        if ($response === 'reject') {
            $updateData = ['driver_id' => null];
            $notesPrefix = '';
            $newStatus = null;

            if ($currentStatus === OrderStatus::DRIVER_PICKUP_ASSIGNED && (int) $order->pickup_driver_id === (int) $driver->id) {
                $updateData['pickup_driver_id'] = null;
                $notesPrefix = __('driver.driver_type_pickup');
                // Revert status so vendor can assign another pickup driver (same as before first assignment)
                $newStatus = OrderStatus::CONFIRMED;
            } elseif ($currentStatus === OrderStatus::DRIVER_DELIVERY_ASSIGNED && (int) $order->delivery_driver_id === (int) $driver->id) {
                $updateData['delivery_driver_id'] = null;
                $notesPrefix = __('driver.driver_type_delivery');
                // Revert to the status before delivery assignment (completed, delivered_to_branch, etc.)
                $newStatus = $this->resolveDeliveryRejectionRevertStatus($order);
            } else {
                throw new \LogicException(__('driver.driver_response_error_cannot_reject', [
                    'id' => $driver->id,
                    'status' => $currentStatus->localizedLabel(),
                ]));
            }

            $updateData['status'] = $newStatus->value;
            $order->update($updateData);
            $driver->update(['is_available' => true]);

            OrderStatusLog::create([
                'order_id' => $order->id,
                'status' => $newStatus->value,
                'notes' => $reason
                    ? __('order.status_log_driver_rejected', [
                        'type' => $notesPrefix,
                        'id' => $driver->id,
                        'reason' => $reason,
                    ])
                    : __('order.status_log_driver_rejected_no_reason', [
                        'type' => $notesPrefix,
                        'id' => $driver->id,
                    ]),
                'changed_by' => $driver->id,
            ]);

            return $order;
        }

        throw new \InvalidArgumentException(__('driver.driver_response_error_invalid', ['response' => $response]));
    }

    /**
     * When a delivery driver rejects, restore the order to the pre-assignment status
     * so the vendor can assign another driver (e.g. completed after laundry finished work).
     */
    protected function resolveDeliveryRejectionRevertStatus(Order $order): OrderStatus
    {
        $deliveryLegStatuses = [
            OrderStatus::DRIVER_DELIVERY_ASSIGNED->value,
            OrderStatus::DRIVER_DELIVERY_ACCEPTED->value,
            OrderStatus::ON_WAY_TO_DELIVERY->value,
            OrderStatus::WAITING_CLIENT_RECEIPT->value,
        ];

        $revertCandidates = [
            OrderStatus::COMPLETED,
            OrderStatus::DELIVERED_TO_BRANCH,
            OrderStatus::CLIENT_POSTPONED_DELIVERY,
        ];

        $logs = OrderStatusLog::where('order_id', $order->id)
            ->orderByDesc('id')
            ->get(['status']);

        foreach ($logs as $log) {
            if (in_array($log->status, $deliveryLegStatuses, true)) {
                continue;
            }

            $status = OrderStatus::tryFrom($log->status);
            if ($status && in_array($status, $revertCandidates, true)) {
                return $status;
            }
        }

        if ($order->vendor_delivery_ready_at !== null) {
            return OrderStatus::COMPLETED;
        }

        return OrderStatus::DELIVERED_TO_BRANCH;
    }

    // ------------------------------------------------------------------
    // Auto-transitions
    // ------------------------------------------------------------------

    private function handleAutoTransitions(Order $order, OrderStatus $justReached, array $context): void
    {
        if ($justReached === OrderStatus::CONFIRMED && (bool) $order->pickup_at_vendor) {
            $this->transitionAfterConfirmed($order, $context, __('order.status_log_auto_reason_branch_dropoff'));

            return;
        }

        // DRIVER_PICKUP_ACCEPTED stays as-is (no auto-advance to payment statuses).

        // Note: DELIVERED status stays as DELIVERED (no auto-transition to COMPLETED)
        // The order should be manually marked as completed when appropriate
    }

    /**
     * Post-confirmation payment step (branch drop-off only):
     * skip waiting_payment when client already paid at checkout or COD.
     */
    private function transitionAfterConfirmed(Order $order, array $context, string $reason): void
    {
        $order->refresh();

        // Paid/COD auto-advance is bookkeeping — keep status logs/events, skip push spam.
        // Real "please pay" (waiting_payment) still notifies below.
        $silentContext = array_merge($context, ['skip_notifications' => true]);

        if ($order->isPaid()) {
            $this->transitionToPaymentConfirmed($order, $silentContext, __('order.status_log_auto_payment_checkout', ['reason' => $reason]));

            return;
        }

        // COD: payment is collected at delivery completion, not after vendor/client approval.
        if (($order->payment_method ?? null) === PaymentMethod::CASH_ON_DELIVERY->value) {
            $this->transitionToPaymentConfirmed($order, $silentContext, __('order.status_log_auto_cod', ['reason' => $reason]));

            return;
        }

        $this->transitionTo($order, OrderStatus::WAITING_PAYMENT, [
            'notes' => __('order.status_log_auto_proceeding_payment', ['reason' => $reason]),
            'changed_by' => $context['changed_by'] ?? null,
        ]);
    }

    /**
     * Move order to payment_confirmed respecting allowed transitions
     * (e.g. confirmed → waiting_payment → payment_confirmed when pickup_at_vendor).
     */
    private function transitionToPaymentConfirmed(Order $order, array $context, string $notes): void
    {
        $order->refresh();
        $target = OrderStatus::PAYMENT_CONFIRMED;

        if ($order->status === $target->value) {
            return;
        }

        $payload = [
            'notes' => $notes,
            'changed_by' => $context['changed_by'] ?? null,
            'skip_notifications' => (bool) ($context['skip_notifications'] ?? false),
        ];

        if ($this->canTransition($order, $target)) {
            $this->transitionTo($order, $target, $payload);

            return;
        }

        // Bridge hop only — never notify "please pay" when we immediately confirm payment.
        if ($this->canTransition($order, OrderStatus::WAITING_PAYMENT)) {
            $this->transitionTo($order, OrderStatus::WAITING_PAYMENT, [
                'notes' => __('order.status_log_auto_internal_payment'),
                'changed_by' => $context['changed_by'] ?? null,
                'skip_notifications' => true,
            ]);
            $order->refresh();
        }

        if ($this->canTransition($order, $target)) {
            $this->transitionTo($order, $target, $payload);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function dispatchEvent(object $event): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => event($event));
        } else {
            event($event);
        }
    }
}
